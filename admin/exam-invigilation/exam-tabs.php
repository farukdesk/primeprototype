<?php
/**
 * Exam workspace tab bar (UX redesign – Phase 3).
 *
 * Include on every per-exam page right after flash_show(), guarded by
 * !$print_mode where applicable. Requires $exam (row from ei_exams) and
 * $id (exam id) in scope. If $f_date is set, it is carried across the
 * two attendance tabs so the selected date is preserved.
 */
$ei_ws_script = basename($_SERVER['PHP_SELF'] ?? '');
$ei_ws_date   = (isset($f_date) && is_string($f_date) && $f_date !== '') ? '&slot_date=' . urlencode($f_date) : '';
$ei_ws_tabs   = [
    ['label' => 'Slots & Assignments', 'icon' => 'fa-th-list',             'script' => 'view.php',              'href' => APP_URL . '/exam-invigilation/view.php?id=' . $id],
    ['label' => 'Attendance',          'icon' => 'fa-calendar-check',      'script' => 'attendance.php',        'href' => APP_URL . '/exam-invigilation/attendance.php?id=' . $id . $ei_ws_date],
    ['label' => 'Officials',           'icon' => 'fa-user-clock',          'script' => 'unique-attendance.php', 'href' => APP_URL . '/exam-invigilation/unique-attendance.php?id=' . $id . $ei_ws_date],
    ['label' => 'Bill',                'icon' => 'fa-file-invoice-dollar', 'script' => 'remuneration-bill.php', 'href' => APP_URL . '/exam-invigilation/remuneration-bill.php?id=' . $id],
    ['label' => 'History',             'icon' => 'fa-history',             'script' => 'versions.php',          'href' => APP_URL . '/exam-invigilation/versions.php?id=' . $id],
];
?>
<div class="card mb-4">
    <div class="card-body pt-3 pb-0 px-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 px-1">
            <div>
                <span class="fw-semibold" style="font-size:1.05rem;"><?= h($exam['exam_name']) ?></span>
                <span class="text-muted ms-1"><?= h($exam['exam_year']) ?></span>
                <?php if ((int)($exam['is_active'] ?? 0) === 1): ?>
                <span class="badge bg-success bg-opacity-15 text-success ms-2">Active</span>
                <?php else: ?>
                <span class="badge bg-secondary bg-opacity-10 text-secondary ms-2">Inactive</span>
                <?php endif; ?>
            </div>
            <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
            <a href="<?= APP_URL ?>/exam-invigilation/edit.php?id=<?= (int)$id ?>"
               class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
                <i class="fas fa-edit me-1"></i> Edit Exam
            </a>
            <?php endif; ?>
        </div>
        <ul class="nav nav-tabs border-bottom-0 flex-wrap">
            <?php foreach ($ei_ws_tabs as $ei_ws_tab): ?>
            <li class="nav-item">
                <a class="nav-link <?= $ei_ws_script === $ei_ws_tab['script'] ? 'active fw-medium' : 'text-secondary' ?>"
                   style="font-size:.875rem;"
                   href="<?= $ei_ws_tab['href'] ?>">
                    <i class="fas <?= $ei_ws_tab['icon'] ?> me-1"></i> <?= $ei_ws_tab['label'] ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
