<?php
/**
 * Student Portal – My Results
 *
 * Shows the logged-in student their PUBLISHED examination results only:
 *   • letter grade (and grade point) for every course, grouped by semester
 *   • semester GPA and running CGPA
 * Marks are intentionally never shown. Draft / pending / returned mark sheets
 * are invisible to students until the approval chain publishes them.
 *
 * Data sources
 *   result_mark_sheets (workflow_status = 'published') + result_sheet_grades
 *       → live results produced by the Results workflow
 *   student_results
 *       → legacy / imported rows (incl. the Final Result Publish CGPA row)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$user = auth_user();

// ── Identify the student record (always scoped to the logged-in portal user) ──
$student = null;
try {
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.status, s.admitted_semester, s.photo,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM students s
           JOIN dept_departments d            ON d.id = s.dept_id
           LEFT JOIN dept_academic_programs p ON p.id = s.program_id
           LEFT JOIN student_batches b        ON b.id = s.batch_id
          WHERE s.portal_user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

if (!$student) {
    flash_set('error', 'No student profile is linked to your account. Please contact the administrator.');
    redirect(APP_URL . '/index.php');
}

$student_pk  = (int)$student['id'];
$student_sid = (string)$student['student_id'];
$page_title  = 'My Results';

/**
 * CGPA policy: when a course (same course code) is taken more than once,
 * only the LATEST attempt is counted in the CGPA (the earlier grade is
 * replaced). Set to false to count every attempt.
 */
const MR_CGPA_LATEST_ATTEMPT_ONLY = true;

// ── Helpers ─────────────────────────────────────────────────────────────────────

/**
 * Normalise a term label such as "Spring-2026", "spring 2026" or "Fall2025"
 * into ['label' => 'Spring 2026', 'sort' => 2026*10 + season].
 * Returns null when the string does not look like a season + year.
 */
function mr_parse_term(?string $raw): ?array
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!preg_match('/\b(spring|summer|fall|autumn|winter)\b\s*[-_\/]?\s*(\d{2,4})/i', $raw, $m)) return null;
    $season = ucfirst(strtolower($m[1]));
    if ($season === 'Autumn') $season = 'Fall';
    $year = (int)$m[2];
    if ($year < 100) $year += 2000;
    $order = ['Spring' => 1, 'Summer' => 2, 'Fall' => 3, 'Winter' => 4][$season] ?? 0;
    return ['label' => $season . ' ' . $year, 'sort' => $year * 10 + $order];
}

/** Bootstrap-ish colour class for a letter grade. */
function mr_grade_class(?string $grade): string
{
    $g = strtoupper(trim((string)$grade));
    if ($g === '' ) return 'mr-g-none';
    if ($g === 'INCOM' || $g === 'I' || $g === 'INC') return 'mr-g-incom';
    if ($g === 'F') return 'mr-g-f';
    if ($g[0] === 'A') return 'mr-g-a';
    if ($g[0] === 'B') return 'mr-g-b';
    if ($g[0] === 'C') return 'mr-g-c';
    if ($g[0] === 'D') return 'mr-g-d';
    return 'mr-g-none';
}

function mr_fmt_gpa(?float $v): string
{
    return $v === null ? '—' : number_format($v, 2);
}

// ── Live published results (Results workflow) ──────────────────────────────────────
$courses = [];
$live_error = false;
try {
    $stmt = db()->prepare(
        "SELECT ms.id AS sheet_id, ms.semester, ms.subject_code, ms.subject_title,
                COALESCE(ms.credits, cc.credit) AS credits,
                ms.exam_id, e.exam_name, e.exam_year, e.end_date AS exam_end_date,
                g.letter_grade, g.grade_point, g.is_absent, g.marks_json, g.remarks,
                (SELECT MAX(h.acted_at) FROM wf_sheet_history h
                  WHERE h.sheet_id = ms.id AND h.action = 'published') AS published_at
           FROM result_sheet_grades g
           JOIN result_mark_sheets ms      ON ms.id = g.sheet_id
           LEFT JOIN course_curriculum cc  ON cc.id = ms.curriculum_id
           LEFT JOIN ei_exams e            ON e.id  = ms.exam_id
          WHERE ms.workflow_status = 'published'
            AND (g.student_id = ? OR g.student_sid = ?)
          ORDER BY ms.id ASC"
    );
    $stmt->execute([$student_pk, $student_sid]);
    $courses = $stmt->fetchAll();
} catch (Throwable $e) {
    // remarks column may not exist on older deployments – retry without it
    try {
        $stmt = db()->prepare(
            "SELECT ms.id AS sheet_id, ms.semester, ms.subject_code, ms.subject_title,
                    COALESCE(ms.credits, cc.credit) AS credits,
                    ms.exam_id, e.exam_name, e.exam_year, e.end_date AS exam_end_date,
                    g.letter_grade, g.grade_point, g.is_absent, g.marks_json, NULL AS remarks,
                    (SELECT MAX(h.acted_at) FROM wf_sheet_history h
                      WHERE h.sheet_id = ms.id AND h.action = 'published') AS published_at
               FROM result_sheet_grades g
               JOIN result_mark_sheets ms      ON ms.id = g.sheet_id
               LEFT JOIN course_curriculum cc  ON cc.id = ms.curriculum_id
               LEFT JOIN ei_exams e            ON e.id  = ms.exam_id
              WHERE ms.workflow_status = 'published'
                AND (g.student_id = ? OR g.student_sid = ?)
              ORDER BY ms.id ASC"
        );
        $stmt->execute([$student_pk, $student_sid]);
        $courses = $stmt->fetchAll();
    } catch (Throwable $e2) {
        $live_error = true;
    }
}

// Group by semester (term label from the course offer; fall back to the exam).
$semesters = [];   // key => ['label','sort','courses'=>[], ...]
foreach ($courses as $c) {
    $letter = trim((string)($c['letter_grade'] ?? ''));
    $graded = ($c['marks_json'] !== null) || (int)$c['is_absent'] === 1 || $letter !== '';
    if (!$graded) continue; // roster row with no marks entered – nothing to show

    $is_incom = ((int)$c['is_absent'] === 1) || strcasecmp($letter, 'Incom') === 0;

    $term = mr_parse_term($c['semester']);
    if (!$term) {
        $exam_label = trim((string)$c['exam_name'] . ' ' . (string)($c['exam_year'] ?? ''));
        $term = [
            'label' => $exam_label !== '' ? $exam_label : (string)$c['semester'],
            'sort'  => ((int)($c['exam_year'] ?? 0)) * 10 + 5,
        ];
    }
    $key = $term['label'];
    if (!isset($semesters[$key])) {
        $semesters[$key] = [
            'label'        => $term['label'],
            'sort'         => $term['sort'],
            'exam'         => trim((string)$c['exam_name'] . ' ' . (string)($c['exam_year'] ?? '')),
            'published_at' => null,
            'courses'      => [],
        ];
    }
    if ($c['published_at'] && ($semesters[$key]['published_at'] === null || $c['published_at'] > $semesters[$key]['published_at'])) {
        $semesters[$key]['published_at'] = $c['published_at'];
    }
    $semesters[$key]['courses'][] = [
        'code'        => (string)($c['subject_code'] ?? ''),
        'title'       => (string)($c['subject_title'] ?? ''),
        'credits'     => $c['credits'] !== null && $c['credits'] !== '' ? (float)$c['credits'] : null,
        'grade'       => $is_incom ? 'Incom' : ($letter !== '' ? $letter : '—'),
        'point'       => $is_incom ? null : ($c['grade_point'] !== null ? (float)$c['grade_point'] : null),
        'is_incom'    => $is_incom,
        'remarks'     => trim((string)($c['remarks'] ?? '')),
    ];
}

// Chronological order for GPA / CGPA computation
uasort($semesters, static fn($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['label'], $b['label']));

// Semester GPA + running CGPA
$cum_points  = 0.0;   // Σ credit × grade point (counted attempts)
$cum_credits = 0.0;   // Σ credits (counted attempts)
$attempts    = [];    // course code => ['credits','point'] currently counted in CGPA
$earned_credits = 0.0;
$total_courses  = 0;
$incom_total    = 0;

foreach ($semesters as $key => &$sem) {
    $sem_points = 0.0; $sem_credits = 0.0; $sem_incom = 0;
    usort($sem['courses'], static fn($a, $b) => strnatcasecmp($a['code'] . $a['title'], $b['code'] . $b['title']));

    foreach ($sem['courses'] as $c) {
        $total_courses++;
        if ($c['is_incom'] || $c['point'] === null) { $sem_incom++; $incom_total++; continue; }
        $cr = $c['credits'] ?? 0.0;
        if ($cr <= 0) continue; // cannot weight without credits

        $sem_points  += $cr * $c['point'];
        $sem_credits += $cr;
        if ($c['point'] > 0) $earned_credits += $cr;

        $code_key = strtoupper(preg_replace('/\s+/', '', $c['code'] !== '' ? $c['code'] : $c['title']));
        if (MR_CGPA_LATEST_ATTEMPT_ONLY && isset($attempts[$code_key])) {
            // Replace the earlier attempt of the same course.
            $prev = $attempts[$code_key];
            $cum_points  -= $prev['credits'] * $prev['point'];
            $cum_credits -= $prev['credits'];
        }
        $attempts[$code_key] = ['credits' => $cr, 'point' => $c['point']];
        $cum_points  += $cr * $c['point'];
        $cum_credits += $cr;
    }

    $sem['gpa']         = $sem_credits > 0 ? round($sem_points / $sem_credits, 2) : null;
    $sem['credits']     = $sem_credits;
    $sem['incom']       = $sem_incom;
    $sem['cgpa']        = $cum_credits > 0 ? round($cum_points / $cum_credits, 2) : null;
    $sem['cum_credits'] = $cum_credits;
}
unset($sem);

$overall_cgpa    = $cum_credits > 0 ? round($cum_points / $cum_credits, 2) : null;
$semesters_desc  = array_reverse($semesters, true); // newest first for display

// ── Legacy / imported results (student_results) ────────────────────────────────────
$legacy_groups = [];
$final_result  = null;
try {
    $stmt = db()->prepare(
        'SELECT * FROM student_results WHERE student_id = ? ORDER BY semester_year ASC, semester ASC, id ASC'
    );
    $stmt->execute([$student_pk]);
    foreach ($stmt->fetchAll() as $r) {
        if (strcasecmp(trim((string)($r['subject'] ?? '')), 'Final Result') === 0) {
            $final_result = $r; // Final Result Publish row (final CGPA)
            continue;
        }
        $lk = trim((string)($r['semester'] ?? '') . ' ' . (string)($r['semester_year'] ?? ''));
        if ($lk === '') $lk = 'Unspecified semester';
        if (!isset($legacy_groups[$lk])) $legacy_groups[$lk] = ['label' => $lk, 'rows' => [], 'gpa' => null, 'cgpa' => null];
        $legacy_groups[$lk]['rows'][] = $r;
        if ($r['gpa']  !== null && $r['gpa']  !== '') $legacy_groups[$lk]['gpa']  = $r['gpa'];
        if ($r['cgpa'] !== null && $r['cgpa'] !== '') $legacy_groups[$lk]['cgpa'] = $r['cgpa'];
    }
} catch (Throwable $e) {}

// Only announce the final CGPA when it is a real number (Final Result Publish
// never stores "Incom"/"withheld" rows, but be defensive).
$final_cgpa = null;
if ($final_result && is_numeric($final_result['cgpa'] ?? null)) {
    $final_cgpa = (float)$final_result['cgpa'];
}

$has_anything = !empty($semesters) || !empty($legacy_groups) || $final_cgpa !== null;

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.mr-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 60%, #3b82f6 100%);
    border-radius: 18px; padding: 24px 28px; margin-bottom: 22px; color: #fff;
    position: relative; overflow: hidden;
}
.mr-hero::before { content:''; position:absolute; top:-40px; right:-40px; width:200px; height:200px; background:rgba(255,255,255,.05); border-radius:50%; }
.mr-hero h2 { font-size: 1.35rem; font-weight: 700; margin: 0 0 4px; }
.mr-hero .meta { font-size: .82rem; color: rgba(255,255,255,.78); }
.mr-stat { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); border-radius: 14px; padding: 12px 18px; min-width: 130px; text-align: center; backdrop-filter: blur(4px); }
.mr-stat .v { font-size: 1.7rem; font-weight: 800; line-height: 1.1; }
.mr-stat .l { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; opacity: .8; }

.sv-card { background:#fff; border:1px solid #e8edf3; border-radius:16px; margin-bottom:20px; overflow:hidden; box-shadow:0 1px 6px rgba(0,0,0,.04); }
.sv-card-header { display:flex; align-items:center; gap:10px; padding:14px 22px; border-bottom:1px solid #f0f3f8; flex-wrap:wrap; }
.sv-card-header-icon { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.sv-card-header-title { font-size:.92rem; font-weight:700; color:#1e293b; margin:0; }
.sv-card-body { padding:18px 22px; }
.sv-table { width:100%; font-size:.86rem; border-collapse:collapse; }
.sv-table thead th { background:#f4f7fc; color:#64748b; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:10px 14px; border-bottom:1px solid #e8edf3; }
.sv-table tbody tr { border-bottom:1px solid #f0f3f8; }
.sv-table tbody tr:last-child { border-bottom:none; }
.sv-table tbody td { padding:10px 14px; color:#1e293b; vertical-align:middle; }
.sv-table tbody tr:hover td { background:#f8faff; }

.mr-sem-pill { font-size:.72rem; font-weight:700; padding:4px 12px; border-radius:20px; background:#eff6ff; color:#1d4ed8; }
.mr-sem-gpa { display:flex; gap:10px; margin-left:auto; flex-wrap:wrap; }
.mr-sem-gpa .box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:6px 14px; text-align:center; min-width:96px; }
.mr-sem-gpa .box .v { font-weight:800; font-size:1.05rem; color:#0f172a; line-height:1.1; }
.mr-sem-gpa .box .l { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
.mr-sem-gpa .box.cg { background:#ecfdf5; border-color:#bbf7d0; }
.mr-sem-gpa .box.cg .v { color:#047857; }

.mr-grade { display:inline-block; min-width:46px; text-align:center; font-weight:800; font-size:.88rem; padding:4px 10px; border-radius:8px; letter-spacing:.02em; }
.mr-g-a     { background:#dcfce7; color:#166534; }
.mr-g-b     { background:#dbeafe; color:#1e40af; }
.mr-g-c     { background:#fef3c7; color:#92400e; }
.mr-g-d     { background:#ffedd5; color:#9a3412; }
.mr-g-f     { background:#fee2e2; color:#991b1b; }
.mr-g-incom { background:#e5e7eb; color:#374151; font-size:.72rem; }
.mr-g-none  { background:#f1f5f9; color:#64748b; }
.mr-point { font-size:.75rem; color:#64748b; }

.mr-empty { text-align:center; padding:56px 24px; color:#64748b; }
.mr-empty i { font-size:2.4rem; color:#cbd5e1; margin-bottom:12px; display:block; }

@media print {
  #sidebar, #topbar, .no-print { display:none !important; }
  #main-wrapper { margin-left:0 !important; }
  .mr-hero { background:#1e3a5f !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .sv-card { break-inside:avoid; }
}
</style>

<?php flash_show(); ?>

<!-- ═══════════════ HERO ═══════════════ -->
<div class="mr-hero">
    <div class="d-flex flex-wrap align-items-center gap-4">
        <div class="flex-fill" style="min-width:220px;">
            <h2><i class="fas fa-chart-line me-2" style="opacity:.85;"></i>My Results</h2>
            <div class="meta">
                <strong style="color:#fff;"><?= h($student['full_name']) ?></strong>
                &nbsp;·&nbsp; <span style="font-family:monospace;"><?= h($student['student_id']) ?></span>
                <?php if ($student['program_name']): ?>&nbsp;·&nbsp; <?= h($student['program_name']) ?><?php endif; ?>
                <br>
                <?= h($student['dept_name']) ?>
                <?php if ($student['batch_name']): ?>&nbsp;·&nbsp; Batch <?= h($student['batch_name']) ?><?php endif; ?>
                <?php if ($student['admitted_semester']): ?>&nbsp;·&nbsp; Admitted <?= h($student['admitted_semester']) ?><?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="mr-stat">
                <div class="v"><?= $final_cgpa !== null ? number_format($final_cgpa, 2) : mr_fmt_gpa($overall_cgpa) ?></div>
                <div class="l"><?= $final_cgpa !== null ? 'Final CGPA' : 'Current CGPA' ?></div>
            </div>
            <div class="mr-stat">
                <div class="v"><?= count($semesters) ?></div>
                <div class="l">Semester<?= count($semesters) === 1 ? '' : 's' ?> published</div>
            </div>
            <div class="mr-stat">
                <div class="v"><?= rtrim(rtrim(number_format($cum_credits, 1), '0'), '.') ?></div>
                <div class="l">Credits counted</div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
    <small class="text-muted"><i class="fas fa-lock me-1"></i>Only results published by the Controller of Examinations appear here.</small>
    <?php if ($has_anything): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" style="border-radius:9px;" onclick="window.print()">
        <i class="fas fa-print me-1"></i> Print
    </button>
    <?php endif; ?>
</div>

<?php if ($live_error): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-1"></i>Results are temporarily unavailable. Please try again later.</div>
<?php endif; ?>

<?php if ($final_cgpa !== null): ?>
<div class="sv-card" style="border-color:#bbf7d0;">
    <div class="sv-card-body d-flex flex-wrap align-items-center gap-3" style="background:linear-gradient(90deg,#ecfdf5,#fff);">
        <div class="sv-card-header-icon" style="background:#d1fae5;color:#047857;width:40px;height:40px;font-size:1rem;"><i class="fas fa-award"></i></div>
        <div class="flex-fill">
            <div class="fw-bold" style="color:#065f46;">Final result published</div>
            <div class="text-muted" style="font-size:.82rem;">
                Completion semester: <strong><?= h(trim((string)($final_result['semester'] ?? '') . ' ' . (string)($final_result['semester_year'] ?? ''))) ?: '—' ?></strong>
                <?php if (!empty($final_result['created_at'])): ?>&nbsp;·&nbsp; Published <?= date('d M Y', strtotime($final_result['created_at'])) ?><?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <div style="font-size:1.9rem;font-weight:800;color:#047857;line-height:1;"><?= number_format($final_cgpa, 2) ?></div>
            <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;">Final CGPA</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$has_anything): ?>
<div class="sv-card">
    <div class="mr-empty">
        <i class="fas fa-hourglass-half"></i>
        <div class="fw-semibold" style="color:#334155;">No results have been published yet</div>
        <div style="font-size:.85rem;">Your results will appear here as soon as they are approved and published.</div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════ SEMESTER RESULTS ═══════════════ -->
<?php foreach ($semesters_desc as $sem): ?>
<div class="sv-card">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-calendar-alt"></i></div>
        <div>
            <h6 class="sv-card-header-title"><?= h($sem['label']) ?></h6>
            <div class="text-muted" style="font-size:.72rem;">
                <?= count($sem['courses']) ?> course<?= count($sem['courses']) === 1 ? '' : 's' ?>
                <?php if ($sem['exam'] !== '' && $sem['exam'] !== $sem['label']): ?>&nbsp;·&nbsp; <?= h($sem['exam']) ?><?php endif; ?>
                <?php if ($sem['published_at']): ?>&nbsp;·&nbsp; Published <?= date('d M Y', strtotime($sem['published_at'])) ?><?php endif; ?>
                <?php if ($sem['incom'] > 0): ?>&nbsp;·&nbsp; <span class="text-danger"><?= $sem['incom'] ?> incomplete</span><?php endif; ?>
            </div>
        </div>
        <div class="mr-sem-gpa">
            <div class="box">
                <div class="v"><?= mr_fmt_gpa($sem['gpa']) ?></div>
                <div class="l">Semester GPA</div>
            </div>
            <div class="box cg">
                <div class="v"><?= mr_fmt_gpa($sem['cgpa']) ?></div>
                <div class="l">CGPA</div>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="sv-table">
            <thead>
                <tr>
                    <th style="padding-left:22px;width:120px;">Code</th>
                    <th>Course Title</th>
                    <th class="text-center" style="width:90px;">Credits</th>
                    <th class="text-center" style="width:130px;">Grade</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sem['courses'] as $c): ?>
                <tr>
                    <td style="padding-left:22px;"><code style="font-size:.8rem;"><?= h($c['code'] !== '' ? $c['code'] : '—') ?></code></td>
                    <td>
                        <?= h($c['title']) ?>
                        <?php if ($c['remarks'] !== ''): ?><div class="text-muted" style="font-size:.72rem;"><i class="fas fa-comment-dots me-1"></i><?= h($c['remarks']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center"><?= $c['credits'] !== null ? rtrim(rtrim(number_format($c['credits'], 2), '0'), '.') : '—' ?></td>
                    <td class="text-center">
                        <span class="mr-grade <?= mr_grade_class($c['grade']) ?>"><?= h($c['grade']) ?></span>
                        <?php if ($c['point'] !== null): ?><div class="mr-point"><?= number_format($c['point'], 2) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- ═══════════════ ARCHIVED / IMPORTED RESULTS ═══════════════ -->
<?php if (!empty($legacy_groups)): ?>
<div class="sv-card">
    <div class="sv-card-header">
        <div class="sv-card-header-icon" style="background:#faf5ff;color:#7c3aed;"><i class="fas fa-archive"></i></div>
        <h6 class="sv-card-header-title">Archived Results</h6>
        <small class="text-muted ms-auto" style="font-size:.72rem;">Results recorded before the online result system</small>
    </div>
    <?php foreach (array_reverse($legacy_groups) as $grp): ?>
    <div class="px-4 pt-3 d-flex align-items-center flex-wrap gap-2">
        <span class="mr-sem-pill"><?= h($grp['label']) ?></span>
        <div class="mr-sem-gpa">
            <?php if ($grp['gpa'] !== null): ?>
            <div class="box"><div class="v"><?= h($grp['gpa']) ?></div><div class="l">GPA</div></div>
            <?php endif; ?>
            <?php if ($grp['cgpa'] !== null): ?>
            <div class="box cg"><div class="v"><?= h($grp['cgpa']) ?></div><div class="l">CGPA</div></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive mb-2">
        <table class="sv-table">
            <thead>
                <tr>
                    <th style="padding-left:22px;width:120px;">Code</th>
                    <th>Course Title</th>
                    <th class="text-center" style="width:90px;">Credits</th>
                    <th class="text-center" style="width:130px;">Grade</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grp['rows'] as $r): ?>
                <tr>
                    <td style="padding-left:22px;"><code style="font-size:.8rem;"><?= h($r['subject_code'] ?: '—') ?></code></td>
                    <td><?= h($r['subject'] ?? '') ?></td>
                    <td class="text-center"><?= h($r['credits'] ?: '—') ?></td>
                    <td class="text-center"><span class="mr-grade <?= mr_grade_class($r['grade'] ?? '') ?>"><?= h($r['grade'] ?: '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($has_anything): ?>
<div class="text-muted" style="font-size:.74rem;">
    <i class="fas fa-info-circle me-1"></i>
    <strong>How GPA / CGPA is calculated:</strong> Semester GPA = Σ(credit × grade point) ÷ Σ credits of graded courses in that semester.
    CGPA is cumulative across all published semesters<?= MR_CGPA_LATEST_ATTEMPT_ONLY ? '; when a course is retaken, the latest attempt replaces the earlier grade' : '' ?>.
    Courses marked <span class="mr-grade mr-g-incom" style="padding:1px 6px;">Incom</span> are excluded until completed.
    The official transcript issued by the Controller of Examinations prevails in case of any discrepancy.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
