<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('departments');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$stmt = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$stmt->execute([$id]);
$dept = $stmt->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($id);

// Counts for each sub-section
$counts = [];
$sections = [
    'faculty'    => 'dept_faculty',
    'events'     => 'dept_events',
    'alumni'     => 'dept_alumni',
    'notices'    => 'dept_notices',
    'routines'   => 'dept_routines',
    'clubs'      => 'dept_clubs',
    'facilities' => 'dept_facilities',
    'academic_programs' => 'dept_academic_programs',
    'prime_pride'       => 'dept_prime_pride',
    'hero_slides'       => 'dept_hero_slides',
];
foreach ($sections as $key => $table) {
    $s = db()->prepare("SELECT COUNT(*) FROM $table WHERE dept_id = ?");
    $s->execute([$id]);
    $counts[$key] = (int)$s->fetchColumn();
}

$page_title = 'Manage: ' . $dept['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item active"><?= h($dept['name']) ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/departments/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-edit me-1"></i> Edit Department Info
    </a>
</div>

<!-- Department Summary -->
<div class="card mb-4">
    <div class="card-body px-4 py-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width:54px;height:54px;border-radius:12px;background:linear-gradient(135deg,#4f8ef7,#7c6ff7);
                display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;">
                <i class="<?= h($dept['hero_icon']) ?>"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold"><?= h($dept['name']) ?></h5>
                <small class="text-muted">
                    Code: <strong><?= h($dept['code']) ?></strong> &nbsp;|&nbsp;
                    <?= h($dept['faculty_label']) ?> &nbsp;|&nbsp;
                    <span class="badge <?= $dept['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $dept['is_active'] ? 'Active' : 'Inactive' ?></span>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Sub-section Cards -->
<?php
$cards = [
    ['key' => 'hero_slides',  'label' => 'Hero Slides',         'icon' => 'fas fa-images',             'color' => '#6c5ce7', 'path' => 'hero-slides'],
    ['key' => 'faculty',    'label' => 'Faculty',           'icon' => 'fas fa-chalkboard-teacher', 'color' => '#4f8ef7', 'path' => 'faculty'],
    ['key' => 'events',     'label' => 'Events',            'icon' => 'fas fa-calendar-alt',       'color' => '#f5a623', 'path' => 'events'],
    ['key' => 'alumni',     'label' => 'Alumni',            'icon' => 'fas fa-user-graduate',      'color' => '#2ecc71', 'path' => 'alumni'],
    ['key' => 'notices',    'label' => 'Notices',           'icon' => 'fas fa-bell',               'color' => '#e74c3c', 'path' => 'notices'],
    ['key' => 'routines',   'label' => 'Routines',          'icon' => 'fas fa-clock',              'color' => '#9b59b6', 'path' => 'routines'],
    ['key' => 'clubs',      'label' => 'Clubs',             'icon' => 'fas fa-users',              'color' => '#1abc9c', 'path' => 'clubs'],
    ['key' => 'facilities', 'label' => 'Facilities',        'icon' => 'fas fa-building',           'color' => '#e67e22', 'path' => 'facilities'],
    ['key' => 'academic_programs', 'label' => 'Academic Programs', 'icon' => 'fas fa-book-open',   'color' => '#3498db', 'path' => 'academic-programs'],
    ['key' => 'prime_pride','label' => 'Prime Pride',       'icon' => 'fas fa-star',               'color' => '#f39c12', 'path' => 'prime-pride'],
];
?>
<div class="row g-4">
<?php foreach ($cards as $card): ?>
<div class="col-lg-4 col-md-6">
    <a href="<?= APP_URL ?>/departments/<?= $card['path'] ?>/index.php?dept_id=<?= $id ?>"
       class="card text-decoration-none h-100" style="transition:.2s;border-radius:12px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3">
                <div style="width:50px;height:50px;border-radius:12px;background:<?= $card['color'] ?>22;
                    display:flex;align-items:center;justify-content:center;
                    color:<?= $card['color'] ?>;font-size:1.3rem;flex-shrink:0;">
                    <i class="<?= $card['icon'] ?>"></i>
                </div>
                <div>
                    <div class="fw-semibold text-dark"><?= $card['label'] ?></div>
                    <div class="text-muted" style="font-size:.85rem;"><?= $counts[$card['key']] ?> record<?= $counts[$card['key']] !== 1 ? 's' : '' ?></div>
                </div>
                <i class="fas fa-chevron-right ms-auto text-muted" style="font-size:.8rem;"></i>
            </div>
        </div>
    </a>
</div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
