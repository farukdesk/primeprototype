<?php
/**
 * ID Card Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

const IDC_TYPES = ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'];

const IDC_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

// ── Permission helpers ────────────────────────────────────────────────────────

function idc_can_view(): bool   { return is_super_admin() || can_access('id-card'); }
function idc_can_create(): bool { return is_super_admin() || can_access('id-card', 'can_create'); }
function idc_can_edit(): bool   { return is_super_admin() || can_access('id-card', 'can_edit'); }
function idc_can_delete(): bool { return is_super_admin() || can_access('id-card', 'can_delete'); }

// ── Data access ───────────────────────────────────────────────────────────────

function idc_get_card(int $id): array|false
{
    $st = db()->prepare(
        'SELECT c.*, u.full_name AS created_by_name
         FROM idc_cards c
         LEFT JOIN users u ON u.id = c.created_by
         WHERE c.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch();
}

/**
 * Dynamic mode: look up a student by their printed Student ID and
 * return everything needed to prefill an ID card.
 */
function idc_find_student(string $student_id): array|false
{
    $st = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.photo, s.blood_group, s.dob, s.phone,
                s.present_address, s.permanent_address, s.status,
                d.name  AS dept_name,  d.code AS dept_code,
                p.program_name,
                b.name  AS batch_name
           FROM students s
           LEFT JOIN dept_departments        d ON d.id = s.dept_id
           LEFT JOIN dept_academic_programs  p ON p.id = s.program_id
           LEFT JOIN student_batches         b ON b.id = s.batch_id
          WHERE s.student_id = ?
          LIMIT 1'
    );
    $st->execute([trim($student_id)]);
    return $st->fetch();
}

// ── Presentation helpers ─────────────────────────────────────────────────────

/** Absolute URL for a stored photo path ('' when no photo). */
function idc_photo_url(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') return '';
    if (preg_match('#^https?://#i', $photo)) return $photo;
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    return $base . '/' . ltrim($photo, '/');
}

/**
 * Public URL of the SVG template for a card type + side ('front'|'back').
 * Faculty/Staff templates are picked up automatically once the files
 * Faculty_ID_Front.svg / Staff_ID_Front.svg (and _Back) are added to the
 * "ID Card SVG" folder; until then the Student template is used as fallback.
 */
function idc_template_url(string $type, string $side): string
{
    $base   = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $side_l = ($side === 'back') ? 'Back' : 'Front';
    $prefix = IDC_TYPES[$type] ?? 'Student';
    $file   = $prefix . '_ID_' . $side_l . '.svg';

    $dir = dirname(__DIR__, 2) . '/ID Card SVG/';
    if (!is_file($dir . $file)) {
        $file = 'Student_ID_' . $side_l . '.svg';
    }
    return $base . '/' . rawurlencode('ID Card SVG') . '/' . rawurlencode($file);
}

/** dd/mm/YYYY or '' */
function idc_fmt_date(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00') return '';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '';
}
