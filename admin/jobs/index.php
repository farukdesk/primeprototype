<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs');

$page_title = 'Job Postings';

$search   = trim($_GET['search']   ?? '');
$status   = $_GET['status']        ?? '';
$job_type = $_GET['job_type']      ?? '';

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(title LIKE ? OR department LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($status === 'published') {
    $where[]  = 'is_published = 1';
} elseif ($status === 'draft') {
    $where[]  = 'is_published = 0';
}
if (in_array($job_type, ['full-time','part-time','contract','internship'], true)) {
    $where[]  = 'job_type = ?';
    $params[] = $job_type;
}

$sql = 'SELECT j.*,
        (SELECT COUNT(*) FROM job_applications a WHERE a.job_id = j.id) AS app_count
        FROM jobs j'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY j.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Jobs</li>
        </ol>
    </nav>
    <?php if (can_access('jobs', 'can_create')): ?>
    <a href="<?= APP_URL ?>/jobs/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Job
    </a>
    <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:280px;border-radius:10px;"
                   placeholder="Search title or department…" value="<?= h($search) ?>">
            <select name="status" class="form-select" style="max-width:160px;border-radius:10px;">
                <option value="">All statuses</option>
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft"     <?= $status === 'draft'     ? 'selected' : '' ?>>Draft</option>
            </select>
            <select name="job_type" class="form-select" style="max-width:170px;border-radius:10px;">
                <option value="">All types</option>
                <option value="full-time"   <?= $job_type === 'full-time'   ? 'selected' : '' ?>>Full-time</option>
                <option value="part-time"   <?= $job_type === 'part-time'   ? 'selected' : '' ?>>Part-time</option>
                <option value="contract"    <?= $job_type === 'contract'    ? 'selected' : '' ?>>Contract</option>
                <option value="internship"  <?= $job_type === 'internship'  ? 'selected' : '' ?>>Internship</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Filter
            </button>
            <?php if ($search || $status || $job_type): ?>
            <a href="<?= APP_URL ?>/jobs/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title / Slug</th>
                        <th>Department</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th>Applications</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No job postings found.</td></tr>
                <?php else: ?>
                    <?php foreach ($jobs as $idx => $job): ?>
                    <?php
                        $type_badge = match($job['job_type']) {
                            'full-time'  => 'bg-primary',
                            'part-time'  => 'bg-secondary',
                            'contract'   => 'bg-warning text-dark',
                            'internship' => 'bg-info text-dark',
                            default      => 'bg-secondary',
                        };
                    ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= h($job['title']) ?></strong>
                            <div style="font-size:.75rem;color:#9ca3af;"><?= h($job['slug']) ?></div>
                        </td>
                        <td><?= h($job['department']) ?: '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span class="badge <?= $type_badge ?>">
                                <?= h(ucfirst($job['job_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <?= $job['is_published']
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                        </td>
                        <td>
                            <?php if ($job['deadline']): ?>
                                <?php
                                $dl  = new DateTime($job['deadline']);
                                $now = new DateTime('today');
                                $color = $dl < $now ? 'text-danger' : 'text-body';
                                ?>
                                <span class="<?= $color ?>"><?= date('M d, Y', strtotime($job['deadline'])) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/jobs/applications.php?job_id=<?= $job['id'] ?>"
                               class="badge bg-light text-dark text-decoration-none border"
                               style="border-radius:20px;">
                                <i class="fas fa-users me-1"></i><?= (int)$job['app_count'] ?>
                            </a>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="<?= APP_URL ?>/jobs/applications.php?job_id=<?= $job['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Applications" style="border-radius:7px;">
                                    <i class="fas fa-users"></i>
                                </a>
                                <?php if (can_access('jobs', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/jobs/edit.php?id=<?= $job['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (can_access('jobs', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/jobs/delete.php"
                                      onsubmit="return confirm('Delete job \"<?= h(addslashes($job['title'])) ?>\"? All applications will also be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
