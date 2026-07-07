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

// ── Allowed class sections ────────────────────────────────────────────────────
const SM_SECTIONS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

// ── Allowed shifts ────────────────────────────────────────────────────────────
const SM_SHIFTS = ['Day', 'Evening', 'Morning'];

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

/**
 * Returns a human-readable label for a semester_type value.
 */
function sm_semester_type_label(string $type, bool $short = false): string
{
    $labels = [
        'bi_semester' => $short ? 'Bi Sem'   : 'Bi Semester (Spring / Fall)',
        'trimester'   => $short ? 'Trimester' : 'Trimester (Spring / Summer / Fall)',
    ];
    return $labels[$type] ?? $type;
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

/**
 * Returns the URL for a student's photo.
 * Checks admin/uploads/students/photos/ first, then the legacy upload_spic/ folder.
 */
function sm_photo_url(?string $photo): string
{
    if (!$photo) return '';
    $new_path = UPLOAD_DIR . '/students/photos/' . $photo;
    if (is_file($new_path)) {
        return UPLOAD_URL . '/students/photos/' . $photo;
    }
    // Legacy: photos were stored in upload_spic/ at the site root.
    // We build the URL via APP_URL minus the /admin segment.
    $base = rtrim(defined('SITE_URL') ? SITE_URL : dirname(APP_URL), '/');
    return $base . '/upload_spic/' . rawurlencode($photo);
}

function sm_status_badge(string $status): string
{
    $map = [
        'Active'           => 'bg-success',
        'Inactive'         => 'bg-secondary',
        'Graduated'        => 'bg-info text-dark',
        'Dropped'          => 'bg-danger',
        'Not Admitted Yet' => 'bg-warning text-dark',
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

// ── Reference data helpers ────────────────────────────────────────────────────

/**
 * Returns all departments with id, name, code, faculty_label as an associative array.
 */
function sm_dept_data(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name, code, faculty_label FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all active programs with id, dept_id, program_name, program_type as an array.
 */
function sm_program_data(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, dept_id, program_name, program_type FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Detects program type from a degree_type string.
 */
function sm_program_type_detect(?string $degree_type): string
{
    if (!$degree_type) return '';
    $dt = strtolower($degree_type);
    if (str_contains($dt, 'bachelor') || str_contains($dt, 'b.sc') || str_contains($dt, 'b.a')
        || str_contains($dt, 'bba') || str_contains($dt, 'llb') || str_contains($dt, 'b.eng')) {
        return 'Bachelor';
    }
    if (str_contains($dt, 'master') || str_contains($dt, 'm.sc') || str_contains($dt, 'm.a')
        || str_contains($dt, 'mba') || str_contains($dt, 'llm')) {
        return 'Masters';
    }
    if (str_contains($dt, 'diploma'))      return 'Diploma';
    if (str_contains($dt, 'certificate'))  return 'Certificate';
    if ($degree_type !== '')               return 'Other';
    return '';
}

/**
 * Returns all active student batches.
 */
function sm_batches(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all active exam titles.
 */
function sm_exam_titles(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name, short_name FROM student_exam_titles WHERE is_active = 1 ORDER BY sort_order, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all active boards.
 */
function sm_boards(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name, short_name FROM student_boards WHERE is_active = 1 ORDER BY sort_order, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all active academic groups.
 */
function sm_academic_groups(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name FROM student_groups WHERE is_active = 1 ORDER BY sort_order, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all Bangladesh districts ordered by division then name.
 */
function sm_bd_districts(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, name, division FROM bd_districts ORDER BY division, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

/**
 * Returns all Bangladesh thanas, keyed by district_id for JS use.
 * Returns flat array with district_id column.
 */
function sm_bd_thanas(): array
{
    static $data = null;
    if ($data === null) {
        $data = db()->query(
            'SELECT id, district_id, name FROM bd_thanas ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

// ── Qual row HTML helper (used in edit.php) ───────────────────────────────────

/**
 * Render HTML fields for one academic qualification row.
 * Includes searchable Exam Title, Academic Board, Academic Group dropdowns.
 *
 * @param int   $idx       Zero-based row index (used as array key in form field names).
 * @param array $q         Existing qualification data; empty array for a blank row.
 * @param array $exams     List from sm_exam_titles().
 * @param array $boards    List from sm_boards().
 * @param array $groups    List from sm_academic_groups().
 * @return string          HTML markup for the row's form fields.
 */
function sm_qual_row_html(int $idx, array $q, array $exams = [], array $boards = [], array $groups = []): string {
    $v  = function($key) use ($q) { return htmlspecialchars((string)($q[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $n  = function($k) use ($idx) { return 'qual[' . $idx . '][' . $k . ']'; };
    $uid = 'q' . $idx; // unique prefix for element IDs

    // Determine display values for searchable fields
    $examVal   = (int)($q['exam_title_id']  ?? 0);
    $boardVal  = (int)($q['board_id']       ?? 0);
    $groupVal  = (int)($q['group_id']       ?? 0);

    // Fallback labels from text fields when IDs are not set
    $examText  = $examVal  ? '' : (string)($q['exam_name']        ?? '');
    $boardText = $boardVal ? '' : (string)($q['board_university']  ?? '');
    $groupText = $groupVal ? '' : (string)($q['group_name']        ?? '');

    ob_start();
    ?>
    <input type="hidden" name="<?= $n('id') ?>" value="<?= (int)($q['id'] ?? 0) ?>">
    <div class="row g-2">
        <!-- Exam Title -->
        <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:.8rem;">Exam Title</label>
            <div class="qual-ss-wrap" style="position:relative;">
                <input type="text" class="form-control form-control-sm qual-ss-trigger"
                       id="<?= $uid ?>_exam_txt" placeholder="SSC, HSC, O Level…"
                       autocomplete="off"
                       value="<?= htmlspecialchars($examText, ENT_QUOTES, 'UTF-8') ?>"
                       data-target="<?= $uid ?>_exam_id">
                <input type="hidden" name="<?= $n('exam_title_id') ?>" id="<?= $uid ?>_exam_id"
                       value="<?= $examVal ?: '' ?>">
                <!-- Also keep text fallback -->
                <input type="hidden" name="<?= $n('exam_name') ?>" id="<?= $uid ?>_exam_name"
                       value="<?= $v('exam_name') ?>">
                <div class="qual-ss-list" style="position:absolute;top:100%;left:0;right:0;max-height:180px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1060;display:none;">
                    <div class="qual-ss-item" data-value="" data-label=""
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None (type manually) —</div>
                    <?php foreach ($exams as $et): ?>
                    <div class="qual-ss-item"
                         data-value="<?= $et['id'] ?>"
                         data-label="<?= h($et['name']) ?>"
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;">
                        <?= h($et['name']) ?><?= $et['short_name'] ? ' <small class="text-muted">(' . h($et['short_name']) . ')</small>' : '' ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- Session -->
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Session</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('session') ?>"
                   value="<?= $v('session') ?>" placeholder="2018-2019">
        </div>
        <!-- Academic Group -->
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Academic Group</label>
            <div class="qual-ss-wrap" style="position:relative;">
                <input type="text" class="form-control form-control-sm qual-ss-trigger"
                       id="<?= $uid ?>_grp_txt" placeholder="Science, Arts…"
                       autocomplete="off"
                       value="<?= htmlspecialchars($groupText, ENT_QUOTES, 'UTF-8') ?>"
                       data-target="<?= $uid ?>_grp_id">
                <input type="hidden" name="<?= $n('group_id') ?>" id="<?= $uid ?>_grp_id"
                       value="<?= $groupVal ?: '' ?>">
                <input type="hidden" name="<?= $n('group_name') ?>" id="<?= $uid ?>_grp_name"
                       value="<?= $v('group_name') ?>">
                <div class="qual-ss-list" style="position:absolute;top:100%;left:0;right:0;max-height:180px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1060;display:none;">
                    <div class="qual-ss-item" data-value="" data-label=""
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None —</div>
                    <?php foreach ($groups as $g): ?>
                    <div class="qual-ss-item"
                         data-value="<?= $g['id'] ?>"
                         data-label="<?= h($g['name']) ?>"
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;">
                        <?= h($g['name']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- Academic Board -->
        <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:.8rem;">Academic Board / University</label>
            <div class="qual-ss-wrap" style="position:relative;">
                <input type="text" class="form-control form-control-sm qual-ss-trigger"
                       id="<?= $uid ?>_board_txt" placeholder="Dhaka Board, NU…"
                       autocomplete="off"
                       value="<?= htmlspecialchars($boardText, ENT_QUOTES, 'UTF-8') ?>"
                       data-target="<?= $uid ?>_board_id">
                <input type="hidden" name="<?= $n('board_id') ?>" id="<?= $uid ?>_board_id"
                       value="<?= $boardVal ?: '' ?>">
                <input type="hidden" name="<?= $n('board_university') ?>" id="<?= $uid ?>_board_name"
                       value="<?= $v('board_university') ?>">
                <div class="qual-ss-list" style="position:absolute;top:100%;left:0;right:0;max-height:180px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1060;display:none;">
                    <div class="qual-ss-item" data-value="" data-label=""
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None (type manually) —</div>
                    <?php foreach ($boards as $b): ?>
                    <div class="qual-ss-item"
                         data-value="<?= $b['id'] ?>"
                         data-label="<?= h($b['name']) ?>"
                         style="padding:5px 10px;cursor:pointer;font-size:.8rem;">
                        <?= h($b['name']) ?><?= $b['short_name'] ? ' <small class="text-muted">(' . h($b['short_name']) . ')</small>' : '' ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- Year of Passing -->
        <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:.8rem;">Year of Passing</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('passing_year') ?>"
                   value="<?= $v('passing_year') ?>" placeholder="2019">
        </div>
        <!-- Division / Grade -->
        <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.8rem;">Division / Class / Grade</label>
            <input type="text" class="form-control form-control-sm" name="<?= $n('division_class_grade') ?>"
                   value="<?= $v('division_class_grade') ?>" placeholder="A+">
        </div>
        <!-- Marks / GPA -->
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
                d.name          AS dept_name,
                d.code          AS dept_code,
                d.faculty_label AS dept_faculty_label,
                p.program_name,
                p.program_type,
                b.name          AS batch_name,
                dist.name       AS district_name,
                dist.division   AS district_division,
                th.name         AS thana_name,
                u.full_name     AS created_by_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         LEFT JOIN student_batches b ON b.id = s.batch_id
         LEFT JOIN bd_districts dist ON dist.id = s.district_id
         LEFT JOIN bd_thanas th ON th.id = s.thana_id
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

// ── Student Portal Helpers ────────────────────────────────────────────────────

/**
 * Read a student_portal_settings value.
 */
function sp_get_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = db()->prepare('SELECT setting_value FROM student_portal_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row !== false ? (string)$row['setting_value'] : $default;
    }
    return $cache[$key];
}

/**
 * Send a student portal SMS via FastSMS BD.
 * Uses credentials stored in student_portal_settings.
 */
function sp_send_sms(string $mobile, string $message): bool
{
    if (sp_get_setting('sms_enabled', '0') !== '1') {
        return false;
    }

    $api_key   = sp_get_setting('sms_api_key', '');
    $sender_id = sp_get_setting('sms_sender_id', '');

    if ($api_key === '' || $sender_id === '' || $mobile === '') {
        return false;
    }

    // Normalize to 880… format
    $mobile = preg_replace('/\D/', '', $mobile);
    if (str_starts_with($mobile, '0')) {
        $mobile = '880' . substr($mobile, 1);
    }

    $url = 'https://smsapi.fastsmsbd.com/smsapiv3?' . http_build_query([
        'apikey'  => $api_key,
        'sender'  => $sender_id,
        'msisdn'  => $mobile,
        'smstext' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    return ($errno === 0 && $response !== false);
}

/**
 * Send the portal welcome SMS to a student using the configured template.
 *
 * @param  array  $student   Student row.
 * @param  string $username  Portal username (optional, used for {{username}}).
 * @param  string $password  Plain-text password (optional, used for {{password}}).
 */
function sp_send_welcome_sms(array $student, string $username = '', string $password = ''): bool
{
    $template = sp_get_setting(
        'sms_template',
        'Dear {{student_name}}, your Student Portal account is ready. Username: {{username}} Password: {{password}} Login: {{login_url}}'
    );
    $message = str_replace(
        ['{{student_name}}', '{{username}}', '{{password}}', '{{login_url}}'],
        [$student['full_name'], $username, $password, APP_URL . '/login.php'],
        $template
    );
    $phone = trim($student['phone'] ?? '');
    if ($phone === '') {
        return false;
    }
    return sp_send_sms($phone, $message);
}

/**
 * Return the portal user row linked to a student, or null.
 */
function sp_get_portal_user(int $student_id): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.last_login,
                g.name AS group_name
         FROM students s
         JOIN users u ON u.id = s.portal_user_id
         JOIN user_groups g ON g.id = u.group_id
         WHERE s.id = ?'
    );
    $stmt->execute([$student_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Generate a cryptographically secure random password.
 * Ensures at least one upper, lower, digit and symbol.
 */
function sp_generate_password(int $length = 12): string
{
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $symbols = '@#$!%*?&';
    $all     = $upper . $lower . $digits . $symbols;

    $password  = $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    // Shuffle so the fixed-position chars above are not predictable
    return str_shuffle($password);
}

// ── Notice notification helper ────────────────────────────────────────────────

/**
 * Send a "student_notice_published" email to all portal students who have an email.
 *
 * @param  array  $notice     Row from cms_notices or dept_notices.
 * @param  string $type       'university' or 'department'
 * @param  int    $dept_id    Required when $type='department'; limits recipients to that dept.
 * @param  string $dept_name  Human-readable department name for the email body.
 */
function sp_notify_students_notice(array $notice, string $type, int $dept_id = 0, string $dept_name = ''): void
{
    require_once __DIR__ . '/../includes/mailer.php';

    $tab        = $type === 'department' ? 'department' : 'university';
    $portal_url = defined('APP_URL') ? APP_URL . '/students/my-notices.php?tab=' . $tab : '';

    // Build recipient list
    $sql = 'SELECT s.name, u.email
            FROM students s
            JOIN users u ON u.id = s.portal_user_id
            WHERE s.portal_user_id IS NOT NULL
              AND u.is_active = 1
              AND u.email IS NOT NULL
              AND u.email != \'\'';
    $params = [];

    if ($type === 'department' && $dept_id > 0) {
        $sql    .= ' AND s.dept_id = ?';
        $params[] = $dept_id;
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll();
    } catch (Throwable $e) {
        return;
    }

    if (empty($recipients)) {
        return;
    }

    // Prepare notice metadata
    $notice_date = '';
    if (!empty($notice['published_at'])) {
        $notice_date = date('d F, Y', strtotime($notice['published_at']));
    } elseif (!empty($notice['notice_date'])) {
        $notice_date = date('d F, Y', strtotime($notice['notice_date']));
    } elseif (!empty($notice['created_at'])) {
        $notice_date = date('d F, Y', strtotime($notice['created_at']));
    }

    $excerpt = '';
    if (!empty($notice['content'])) {
        $clean   = strip_tags($notice['content']);
        $excerpt = mb_strimwidth($clean, 0, 300, '…');
    }

    $notice_type = $type === 'department' ? 'Department Notice' : 'University Notice';

    $dept_name_html = '';
    if ($type === 'department' && $dept_name !== '') {
        $dept_name_html = '<div class="dept-badge">&#127979; '
            . htmlspecialchars($dept_name, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $excerpt_html = '';
    if ($excerpt !== '') {
        $excerpt_html = '<div class="notice-excerpt">'
            . nl2br(htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')) . '</div>';
    }

    foreach ($recipients as $r) {
        send_template_email(
            'student_notice_published',
            $r['email'],
            $r['name'],
            [
                'student_name'    => $r['name'],
                'notice_title'    => $notice['title'],
                'notice_date'     => $notice_date,
                'notice_type'     => $notice_type,
                'dept_name_html'  => $dept_name_html,
                'excerpt_html'    => $excerpt_html,
                'portal_url'      => $portal_url,
            ]
        );
    }

    // ── FCM Push Notifications to student mobile app ─────────────────────────
    sp_send_fcm_notice_push($notice, $type, $dept_id, $dept_name);
}

/**
 * Send FCM push notification to all portal students (or a single department)
 * when a new notice is published.  Tokens are stored in student_push_tokens.
 *
 * @param array  $notice   The notice row (must have 'title' and optionally 'content').
 * @param string $type     'university' or 'department'
 * @param int    $dept_id  When $type='department', only sends to students in this dept.
 * @param string $dept_name Human-readable department name (used in notification body).
 */
function sp_send_fcm_notice_push(array $notice, string $type, int $dept_id = 0, string $dept_name = ''): void
{
    // Read FCM server key configured in admin settings
    static $server_key = null;
    if ($server_key === null) {
        try {
            $row = db()->query(
                "SELECT `value` FROM settings WHERE `key` = 'student_fcm_server_key' LIMIT 1"
            )->fetch();
            $server_key = $row ? trim((string)$row['value']) : '';
            // Fall back to the shared admin FCM key if no student-specific key is set
            if ($server_key === '') {
                $row2 = db()->query(
                    "SELECT `value` FROM settings WHERE `key` = 'fcm_server_key' LIMIT 1"
                )->fetch();
                $server_key = $row2 ? trim((string)$row2['value']) : '';
            }
        } catch (Throwable $e) {
            $server_key = '';
        }
    }

    if ($server_key === '') {
        error_log('PUMIS Student FCM: server key is not configured.');
        return;
    }

    // Fetch FCM tokens for the relevant students
    try {
        if ($type === 'department' && $dept_id > 0) {
            $stmt = db()->prepare(
                "SELECT DISTINCT spt.fcm_token
                 FROM student_push_tokens spt
                 JOIN users u ON u.id = spt.user_id
                 JOIN students s ON s.portal_user_id = u.id
                 WHERE s.dept_id = ?
                   AND u.is_active = 1
                   AND spt.fcm_token IS NOT NULL
                   AND spt.fcm_token != ''"
            );
            $stmt->execute([$dept_id]);
        } else {
            $stmt = db()->prepare(
                "SELECT DISTINCT spt.fcm_token
                 FROM student_push_tokens spt
                 JOIN users u ON u.id = spt.user_id
                 JOIN students s ON s.portal_user_id = u.id
                 WHERE u.is_active = 1
                   AND spt.fcm_token IS NOT NULL
                   AND spt.fcm_token != ''"
            );
            $stmt->execute();
        }
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('PUMIS Student FCM: could not fetch tokens – ' . $e->getMessage());
        return;
    }

    if (empty($tokens)) {
        return;
    }

    // Build notification payload
    $title = $notice['title'] ?? 'New Notice';
    $body_text = '';
    if (!empty($notice['content'])) {
        $body_text = mb_strimwidth(strip_tags((string)$notice['content']), 0, 120, '…');
    }
    if ($body_text === '' && $type === 'department' && $dept_name !== '') {
        $body_text = $dept_name . ' has posted a new notice.';
    }
    if ($body_text === '') {
        $body_text = ($type === 'department' ? 'New department notice.' : 'New university notice.');
    }

    $data_payload = [
        'notice_type' => $type,
        'dept_id'     => (string)$dept_id,
        'screen'      => 'notices',
    ];

    // Send in batches of 500 (FCM legacy multicast limit is 1000)
    foreach (array_chunk($tokens, 500) as $batch) {
        _sp_fcm_multicast($server_key, $batch, $title, $body_text, $data_payload);
    }
}

/**
 * Send a single FCM multicast message to up to 500 device tokens.
 */
function _sp_fcm_multicast(string $server_key, array $tokens, string $title, string $body, array $data): void
{
    $payload = json_encode([
        'registration_ids' => $tokens,
        'notification'     => [
            'title'        => $title,
            'body'         => $body,
            'sound'        => 'default',
            'badge'        => '1',
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ],
        'data'             => array_merge($data, ['title' => $title, 'body' => $body]),
        'priority'         => 'high',
        'content_available'=> true,
    ]);

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: key=' . $server_key,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || $response === false) {
        error_log('PUMIS Student FCM multicast: HTTP ' . $http . ' – ' . ($response ?: 'no response'));
    }
}
