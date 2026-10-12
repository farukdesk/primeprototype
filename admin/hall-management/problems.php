<?php
/**
 * Hall Management – Problem / Error report.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$page_title = 'Hall Problem Report';

$f_date = trim((string)($_GET['exam_date'] ?? ''));
if ($f_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
if ($f_date === '') $f_date = date('Y-m-d');

$f_dept = (int)($_GET['dept_id'] ?? 0);
$departments = hm_departments(true);
$report = hm_problem_report($f_date, ['dept_id' => $f_dept]);

$total_issues = 0;
foreach ($report as $rows) $total_issues += count($rows);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active">Problem Report</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/hall-management/index.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Halls
    </a>
</div>

<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-medium mb-1">Exam Date <span class="text-danger">*</span></label>
                <input type="date" name="exam_date" class="form-control form-control-sm" value="<?= h($f_date) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-medium mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-filter me-1"></i> Check Problems</button>
                <a href="<?= APP_URL ?>/hall-management/problems.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
            <div class="col-md-3 text-md-end">
                <?php if ($total_issues > 0): ?>
                <span class="badge bg-danger"><?= $total_issues ?> issue(s) found</span>
                <?php else: ?>
                <span class="badge bg-success">No issues found</span>
                <?php endif; ?>
            </div>
        </form>
        <div class="form-text mt-2">
            Checks common scheduling and seat-assignment problems for <strong><?= h(date('d M Y', strtotime($f_date))) ?></strong>.
        </div>
    </div>
</div>

<?php
$sections = [
    'exam_clashes' => [
        'title' => 'Same student has multiple exams at same time',
        'icon'  => 'fa-exclamation-triangle text-danger',
        'cols'  => ['Student', 'Department', 'Time Slot', 'Courses'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['dept_name'] ?? '—'),
                $r['time_slot'] !== '' ? h($r['time_slot']) : '<span class="text-muted">— (no time)</span>',
                h($r['courses']),
            ];
        },
    ],
    'missing_time_slots' => [
        'title' => 'Admit-card exam rows missing time slot',
        'icon'  => 'fa-clock text-warning',
        'cols'  => ['Student', 'Department', 'Courses without time'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['dept_name'] ?? '—'),
                h($r['courses']),
            ];
        },
    ],
    'double_seated' => [
        'title' => 'Student seated more than once on the same date',
        'icon'  => 'fa-chair text-danger',
        'cols'  => ['Student', 'Department', 'Seat count', 'Seat details'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['dept_name'] ?? '—'),
                h((string)$r['seat_count']),
                h($r['seats']),
            ];
        },
    ],
    'no_exam_assignment' => [
        'title' => 'Seated student has no active exam on this date',
        'icon'  => 'fa-user-slash text-warning',
        'cols'  => ['Student', 'Department', 'Room', 'Seat'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['dept_name'] ?? '—'),
                h($r['room_number']),
                h($r['seat']),
            ];
        },
    ],
    'hall_time_mismatch' => [
        'title' => 'Hall time does not match student exam start time',
        'icon'  => 'fa-calendar-times text-danger',
        'cols'  => ['Student', 'Room/Seat', 'Hall Time', 'Student Exam Start(s)'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['room_number'] . ' / ' . $r['seat']),
                h($r['hall_exam_time']),
                h($r['student_starts']),
            ];
        },
    ],
    'hall_mixed_times' => [
        'title' => 'Hall contains students from multiple exam start times',
        'icon'  => 'fa-random text-warning',
        'cols'  => ['Room', 'Hall Time', 'Detected Start Times', 'Students'],
        'render'=> static function(array $r): array {
            return [
                h($r['room_number']),
                ($r['hall_exam_time'] ?? '') !== '' ? h(substr((string)$r['hall_exam_time'], 0, 5)) : '<span class="text-muted">—</span>',
                h($r['times']),
                h((string)$r['student_count']),
            ];
        },
    ],
    'inactive_assigned' => [
        'title' => 'Inactive students are seated',
        'icon'  => 'fa-user-times text-danger',
        'cols'  => ['Student', 'Status', 'Room', 'Seat'],
        'render'=> static function(array $r): array {
            return [
                h($r['full_name']) . '<br><small class="text-muted">' . h($r['student_id']) . '</small>',
                h($r['status']),
                h($r['room_number']),
                h($r['seat']),
            ];
        },
    ],
];
?>

<?php foreach ($sections as $key => $cfg): $rows = $report[$key] ?? []; ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;">
        <div class="fw-semibold">
            <i class="fas <?= h($cfg['icon']) ?> me-2"></i><?= h($cfg['title']) ?>
        </div>
        <span class="badge <?= $rows ? 'bg-danger' : 'bg-success' ?>"><?= count($rows) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
        <div class="text-center text-muted py-4">No issue found in this category.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:45px;">#</th>
                        <?php foreach ($cfg['cols'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): $cells = $cfg['render']($r); ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <?php foreach ($cells as $cell): ?><td><?= $cell ?></td><?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
