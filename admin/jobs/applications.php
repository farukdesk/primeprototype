<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs');

$page_title = 'Job Applications';

$job_id_filter = (int)($_GET['job_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';

$where  = [];
$params = [];

if ($job_id_filter > 0) {
    $where[]  = 'a.job_id = ?';
    $params[] = $job_id_filter;
}
if (in_array($status_filter, ['pending','reviewing','shortlisted','rejected'], true)) {
    $where[]  = 'a.status = ?';
    $params[] = $status_filter;
}

$sql = 'SELECT a.*, j.title AS job_title
        FROM job_applications a
        JOIN jobs j ON j.id = a.job_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.applied_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Fetch all jobs for filter dropdown
$all_jobs = db()->query('SELECT id, title FROM jobs ORDER BY title ASC')->fetchAll();

// Get filtered job title if filtering
$filtered_job = null;
if ($job_id_filter > 0) {
    $st = db()->prepare('SELECT title FROM jobs WHERE id = ?');
    $st->execute([$job_id_filter]);
    $filtered_job = $st->fetchColumn();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/jobs/index.php">Jobs</a></li>
            <li class="breadcrumb-item active">Applications<?= $filtered_job ? ' – ' . h($filtered_job) : '' ?></li>
        </ol>
    </nav>
    <?php if ($job_id_filter): ?>
    <a href="<?= APP_URL ?>/jobs/applications.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-list me-1"></i> All Applications
    </a>
    <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <select name="job_id" class="form-select" style="max-width:260px;border-radius:10px;">
                <option value="">All Jobs</option>
                <?php foreach ($all_jobs as $j): ?>
                <option value="<?= $j['id'] ?>" <?= $job_id_filter === (int)$j['id'] ? 'selected' : '' ?>>
                    <?= h($j['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select" style="max-width:170px;border-radius:10px;">
                <option value="">All Statuses</option>
                <option value="pending"     <?= $status_filter === 'pending'     ? 'selected' : '' ?>>Pending</option>
                <option value="reviewing"   <?= $status_filter === 'reviewing'   ? 'selected' : '' ?>>Reviewing</option>
                <option value="shortlisted" <?= $status_filter === 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                <option value="rejected"    <?= $status_filter === 'rejected'    ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Filter
            </button>
            <?php if ($job_id_filter || $status_filter): ?>
            <a href="<?= APP_URL ?>/jobs/applications.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th>Applicant</th>
                        <th>Email</th>
                        <th>Job</th>
                        <th>Status</th>
                        <th>Applied At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($applications)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No applications found.</td></tr>
                <?php else: ?>
                    <?php foreach ($applications as $idx => $app): ?>
                    <?php
                        $status_badge = match($app['status']) {
                            'pending'     => 'bg-warning text-dark',
                            'reviewing'   => 'bg-info text-dark',
                            'shortlisted' => 'bg-success',
                            'rejected'    => 'bg-danger',
                            default       => 'bg-secondary',
                        };
                    ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td><strong><?= h($app['full_name']) ?></strong></td>
                        <td><?= h($app['email']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/jobs/applications.php?job_id=<?= $app['job_id'] ?>"
                               class="text-decoration-none text-body">
                                <?= h($app['job_title']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge <?= $status_badge ?>">
                                <?= ucfirst(h($app['status'])) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y H:i', strtotime($app['applied_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/jobs/application-view.php?id=<?= $app['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (can_access('jobs', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/jobs/application-delete.php"
                                      onsubmit="return confirm('Delete application from <?= h(addslashes($app['full_name'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
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
