<?php
/**
 * Student Management – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Allowed file types ────────────────────────────────────────────────────────
const SM_PHOTO_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const SM_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const SM_FILE_EXTS   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt'];
const SM_FILE_MIMES  = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip', 'application/x-zip-compressed',
    'text/plain',
];
const SM_PHOTO_MAX   = 5 * 1024 * 1024;  // 5 MB
const SM_FILE_MAX    = 20 * 1024 * 1024; // 20 MB

// ── Permission helpers ────────────────────────────────────────────────────────

function sm_is_staff(): bool
{
    return is_super_admin() || can_access('students', 'can_edit');
}

function sm_can_create(): bool
{
    return is_super_admin() || can_access('students', 'can_create');
}

function sm_can_delete(): bool
{
    return is_super_admin() || can_access('students', 'can_delete');
}

// ── Semester list ─────────────────────────────────────────────────────────────

/**
 * Returns an ordered list of admitted semesters from Summer 2002 to end of 2027.
 */
function sm_semester_list(): array
{
    $list = [];
    for ($y = 2002; $y <= 2027; $y++) {
        $list[] = 'Summer ' . $y;
        $list[] = 'Fall '   . $y;
        $list[] = 'Spring ' . $y;
    }
    return $list;
}

// ── Student ID generator ──────────────────────────────────────────────────────

/**
 * Derive the 2-digit semester code from a semester string.
 * Summer=01, Fall=02, Spring=03
 */
function sm_semester_code(string $semester): string
{
    $sem = strtolower(explode(' ', trim($semester))[0] ?? '');
    return match ($sem) {
        'summer' => '01',
        'fall'   => '02',
        'spring' => '03',
        default  => '00',
    };
}

/**
 * Extract 2-digit year from "Summer 2025" → "25"
 */
function sm_semester_year(string $semester): string
{
    $parts = explode(' ', trim($semester));
    $year  = end($parts);
    return str_pad(substr($year, -2), 2, '0', STR_PAD_LEFT);
}

/**
 * Generate a unique 12-digit student ID.
 *
 * Format: [YY][SS][DD][PP][NNNN]
 *   YY   = last 2 digits of admission year
 *   SS   = semester code (01=Summer, 02=Fall, 03=Spring)
 *   DD   = dept_id zero-padded to 2 digits
 *   PP   = program_id zero-padded to 2 digits (00 if none)
 *   NNNN = 4-digit sequential counter for this prefix
 */
function sm_generate_student_id(string $admitted_semester, int $dept_id, int $program_id = 0): string
{
    $yy   = sm_semester_year($admitted_semester);
    $ss   = sm_semester_code($admitted_semester);
    $dd   = str_pad((string)$dept_id,    2, '0', STR_PAD_LEFT);
    $pp   = str_pad((string)$program_id, 2, '0', STR_PAD_LEFT);
    $prefix = $yy . $ss . $dd . $pp;

    $stmt = db()->prepare(
        "SELECT student_id FROM students
         WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, -4) + 1 : 1;

    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── Upload helpers ────────────────────────────────────────────────────────────

function sm_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > SM_PHOTO_MAX)     return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SM_PHOTO_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SM_PHOTO_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/students/photos';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

function sm_upload_file(array $file): array|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > SM_FILE_MAX)      return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SM_FILE_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SM_FILE_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/students/files';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return [
        'stored_name'   => $stored,
        'original_name' => $file['name'],
        'mime_type'     => $mime,
        'file_size'     => $file['size'],
    ];
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function sm_status_badge(string $status): string
{
    $map = [
        'Active'    => 'bg-success',
        'Inactive'  => 'bg-secondary',
        'Graduated' => 'bg-info text-dark',
        'Dropped'   => 'bg-danger',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

function sm_sex_badge(string $sex): string
{
    $map = [
        'Male'   => 'bg-primary',
        'Female' => 'bg-pink',
        'Other'  => 'bg-secondary',
    ];
    $cls = $map[$sex] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($sex) . '</span>';
}

function sm_file_icon(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf'              => 'fas fa-file-pdf text-danger',
        'doc', 'docx'      => 'fas fa-file-word text-primary',
        'xls', 'xlsx'      => 'fas fa-file-excel text-success',
        'ppt', 'pptx'      => 'fas fa-file-powerpoint text-warning',
        'zip'              => 'fas fa-file-archive text-secondary',
        'jpg', 'jpeg',
        'png', 'gif', 'webp' => 'fas fa-file-image text-info',
        'txt'              => 'fas fa-file-alt text-muted',
        default            => 'fas fa-file text-muted',
    };
}

function sm_format_size(int $bytes): string
{
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

// ── Qual row HTML helper (used in edit.php) ───────────────────────────────────

/**
 * Render HTML fields for one academic qualification row.
 *
 * @param int   $idx Zero-based row index (used as array key in form field names).
 * @param array $q   Existing qualification data; empty array for a blank row.
 * @return string    HTML markup for the row's form fields.
 */
function sm_qual_row_html(int $idx, array $q): string {
    $v = function($key) use ($q) { return htmlspecialchars((string)($q[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $n = function($k) use ($idx) { return 'qual[' . $idx . '][' . $k . ']'; };
    ob_start();
    ?>
    <input type="hidden" name="<?= $n('id') ?>" value="<?= (int)($q['id'] ?? 0) ?>">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:.8rem;">Exam Name</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('exam_name') ?>"
                   value="<?= $v('exam_name') ?>" placeholder="e.g. SSC, HSC, B.Sc.">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Session</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('session') ?>"
                   value="<?= $v('session') ?>" placeholder="2018-2019">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Group</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('group_name') ?>"
                   value="<?= $v('group_name') ?>" placeholder="Science">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:.8rem;">Board / University</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('board_university') ?>"
                   value="<?= $v('board_university') ?>" placeholder="Dhaka Board">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Year of Passing</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('passing_year') ?>"
                   value="<?= $v('passing_year') ?>" placeholder="2019">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.8rem;">Division / Class / Grade</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('division_class_grade') ?>"
                   value="<?= $v('division_class_grade') ?>" placeholder="A+">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label" style="font-size:.8rem;">Obtained Marks / GPA / CGPA</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('obtained_marks_gpa') ?>"
                   value="<?= $v('obtained_marks_gpa') ?>" placeholder="5.00">
        </div>
    </div>
    <?php
    return ob_get_clean();
}


function sm_get_student(int $id): array
{
    $stmt = db()->prepare(
        'SELECT s.*,
                d.name  AS dept_name,
                d.code  AS dept_code,
                p.program_name,
                u.full_name AS created_by_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         LEFT JOIN users u ON u.id = s.created_by
         WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $student = $stmt->fetch();

    if (!$student) {
        flash_set('error', 'Student not found.');
        redirect(APP_URL . '/students/index.php');
    }

    return $student;
}
