<?php
/**
 * Shared sub-navigation for the Exam Invigilation module.
 * Include on every module page right after flash_show().
 */
$ei_nav_script = basename($_SERVER['PHP_SELF'] ?? '');
$ei_nav_map = [
    'overview' => ['index.php', 'create.php', 'edit.php', 'view.php', 'slot-create.php', 'slot-edit.php', 'attendance.php', 'unique-attendance.php', 'versions.php'],
    'faculty'  => ['faculty.php', 'faculty-create.php', 'faculty-edit.php', 'faculty-import.php', 'faculty-attendance.php'],
    'bills'    => ['remuneration-bills.php', 'remuneration-bill.php'],
    'reports'  => ['reports.php'],
];
$ei_nav_active = 'overview';
foreach ($ei_nav_map as $ei_nav_tab => $ei_nav_scripts) {
    if (in_array($ei_nav_script, $ei_nav_scripts, true)) {
        $ei_nav_active = $ei_nav_tab;
        break;
    }
}
$ei_nav_items = [
    'overview' => ['label' => 'Overview & Exams',   'icon' => 'fa-th-large',            'href' => APP_URL . '/exam-invigilation/index.php'],
    'faculty'  => ['label' => 'Faculty Pool',        'icon' => 'fa-users',               'href' => APP_URL . '/exam-invigilation/faculty.php'],
    'bills'    => ['label' => 'Remuneration Bills',  'icon' => 'fa-file-invoice-dollar', 'href' => APP_URL . '/exam-invigilation/remuneration-bills.php'],
    'reports'  => ['label' => 'Reports',             'icon' => 'fa-chart-bar',           'href' => APP_URL . '/exam-invigilation/reports.php'],
];
?>
<div class="card mb-4">
    <div class="card-body py-2 px-3">
        <ul class="nav nav-pills flex-wrap gap-1 mb-0">
            <?php foreach ($ei_nav_items as $ei_nav_key => $ei_nav_item): ?>
            <li class="nav-item">
                <a class="nav-link py-1 px-3 <?= $ei_nav_active === $ei_nav_key ? 'active' : 'text-secondary' ?>"
                   style="border-radius:8px;font-size:.875rem;"
                   href="<?= $ei_nav_item['href'] ?>">
                    <i class="fas <?= $ei_nav_item['icon'] ?> me-1"></i> <?= $ei_nav_item['label'] ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
