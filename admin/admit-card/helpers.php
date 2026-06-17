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
    $tmp   = tempnam(sys_get_temp_dir(), 'qr_');
    $png_f = $tmp . '.png';
    @unlink($tmp); // remove the empty placeholder created by tempnam
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
    $tmp2  = tempnam(sys_get_temp_dir(), 'qr_');
    $svg_f = $tmp2 . '.svg';
    @unlink($tmp2);
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

function ac_build_html(array $card, array $student, array $courses, string $qr_data_uri, float $total_due): string
{
    $logo_uri = acc_logo_data_uri();
    $dept_label = h($card['dept_faculty_label'] ?? $card['dept_name']);
    $program_label = h($card['program_name']);
    $exam_name  = h($card['exam_name']);
    $semester   = h($card['semester']);
    $batch_label = h($card['batch_label'] ?? ($card['batch_name_db'] ?? ''));
    $student_name = h($student['full_name']);
    $student_id   = h($student['student_id']);

    // Student photo
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
    if ($photo_html === '') {
        $photo_html = '<div style="width:95px;height:115px;border:1px solid #ddd;display:flex;
                                    align-items:center;justify-content:center;font-size:11px;
                                    color:#777;">No Photo</div>';
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

    // Due info line for the card (if any)
    $due_note = '';
    if ($total_due > 0) {
        $due_note = '<div style="margin:8px 0;padding:4px 8px;background:#fff3cd;border:1px solid #ffc107;
                                  font-size:12px;color:#856404;border-radius:4px;">
                        Total outstanding dues: ৳' . number_format($total_due, 2) . '
                    </div>';
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

  <!-- Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <div style="width:120px;text-align:center;">' . $logo_img . '</div>
    <div style="text-align:center;line-height:1.4;">
      <h1 style="margin:0;font-size:22px;font-weight:bold;">Prime University</h1>
      <div style="font-size:15px;font-weight:bold;">' . $dept_label . '</div>
      <div style="font-size:15px;font-weight:bold;">' . $program_label . '</div>
    </div>
    <div style="width:120px;text-align:center;">' . $photo_html . '</div>
  </div>

  <!-- Admit Card title -->
  <div style="text-align:center;margin:20px 0 14px 0;">
    <span style="font-size:21px;font-weight:bold;border:3px solid #000;padding:2px 22px;display:inline-block;">Admit Card</span>
  </div>

  ' . $due_note . '

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

  <!-- Footer with QR -->
  <div style="margin-top:20px;display:flex;justify-content:space-between;align-items:flex-end;">
    <div style="font-size:11px;color:#555;max-width:500px;line-height:1.5;">
      <p style="margin:0 0 4px 0;">
        <strong>Controller of Examinations</strong><br>Prime University
      </p>
      <p style="margin:8px 0 0 0;font-style:italic;color:#444;">
        This is a digitally generated admit card. You can authenticate it by scanning the QR code.
      </p>
    </div>
    <div style="text-align:center;">
      <img src="' . $qr_data_uri . '" style="width:100px;height:100px;" alt="QR Code">
      <div style="font-size:9px;color:#666;margin-top:2px;">Scan to verify</div>
    </div>
  </div>

</div>
</body></html>';

    return $html;
}
