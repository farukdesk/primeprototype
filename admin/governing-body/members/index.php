<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('governing-body');

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];
$page_type   = $_GET['page_type'] ?? '';
if (!in_array($page_type, $valid_types, true)) {
    flash_set('error', 'Invalid page type.');
    redirect(APP_URL . '/governing-body/index.php');
}

// Load page settings for title display
$st = db()->prepare('SELECT title FROM governing_body_pages WHERE page_type = ? LIMIT 1');
$st->execute([$page_type]);
$pg_row      = $st->fetch();
$section_title = $pg_row ? $pg_row['title'] : ucwords(str_replace('-', ' ', $page_type));

$page_title = 'Members – ' . $section_title;

$search  = trim($_GET['search'] ?? '');
$section = trim($_GET['section'] ?? '');

$where  = ['page_type = ?'];
$params = [$page_type];

if ($search !== '') {
    $where[]  = '(full_name LIKE ? OR designation LIKE ? OR department LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($section !== '') {
    $where[]  = 'section = ?';
    $params[] = $section;
}

$sql     = 'SELECT * FROM governing_body_members WHERE ' . implode(' AND ', $where)
         . ' ORDER BY sort_order ASC, id ASC';
$stmt    = db()->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// Distinct sections for filter dropdown
$sec_stmt = db()->prepare('SELECT DISTINCT section FROM governing_body_members WHERE page_type = ? ORDER BY section');
$sec_stmt->execute([$page_type]);
$sections = $sec_stmt->fetchAll(\PDO::FETCH_COLUMN);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/governing-body/index.php">Governing Body</a></li>
            <li class="breadcrumb-item active"><?= h($section_title) ?> – Members</li>
        </ol>
    </nav>
    <?php if (can_access('governing-body', 'can_create')): ?>
    <a href="<?= APP_URL ?>/governing-body/members/create.php?page_type=<?= urlencode($page_type) ?>"
       class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Member
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="hidden" name="page_type" value="<?= h($page_type) ?>">
            <input type="text" name="search" class="form-control" style="max-width:260px;border-radius:10px;"
                   placeholder="Search name, designation…" value="<?= h($search) ?>">
            <?php if (!empty($sections)): ?>
            <select name="section" class="form-select" style="max-width:200px;border-radius:10px;">
                <option value="">All sections</option>
                <?php foreach ($sections as $sec): ?>
                <option value="<?= h($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>><?= h(ucfirst($sec)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Filter
            </button>
            <?php if ($search || $section): ?>
            <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($page_type) ?>"
               class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th style="width:64px;">Photo</th>
                        <th>Name / Designation</th>
                        <th>Section</th>
                        <th>Department</th>
                        <th>Featured</th>
                        <th style="width:60px;">Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No members found.
                            <?php if (can_access('governing-body', 'can_create')): ?>
                            <a href="<?= APP_URL ?>/governing-body/members/create.php?page_type=<?= urlencode($page_type) ?>">Add the first member.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $idx => $m): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <?php if ($m['photo']): ?>
                            <img src="<?= UPLOAD_URL ?>/governing-body/<?= h($m['photo']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;" alt="">
                            <?php else: ?>
                            <div style="width:46px;height:46px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-user" style="color:#94a3b8;font-size:.9rem;"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= h($m['full_name']) ?></strong>
                            <?php if ($m['designation']): ?>
                            <div style="font-size:.75rem;color:#9ca3af;"><?= h($m['designation']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge" style="background:rgba(0,33,71,.08);color:#002147;border-radius:20px;font-size:.75rem;font-weight:500;">
                                <?= h(ucfirst($m['section'])) ?>
                            </span>
                        </td>
                        <td><?= $m['department'] ? h($m['department']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($m['is_featured']): ?>
                            <span class="badge bg-warning text-dark" style="border-radius:20px;font-size:.73rem;">
                                <i class="fas fa-star me-1"></i>Featured
                            </span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$m['sort_order'] ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if (can_access('governing-body', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/governing-body/members/edit.php?id=<?= $m['id'] ?>&page_type=<?= urlencode($page_type) ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (can_access('governing-body', 'can_delete')): ?>
                                <form method="POST"
                                      action="<?= APP_URL ?>/governing-body/members/delete.php"
                                      onsubmit="return confirm('Remove ' + <?= json_encode($m['full_name']) ?> + '?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="page_type" value="<?= h($page_type) ?>">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
