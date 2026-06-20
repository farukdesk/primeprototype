<?php
/**
 * Admissions Top Sheet – Settings Page
 *
 * Allows configuring:
 *  1. General labels (semester label, months, admission-period label)
 *  2. Program display labels & full names for the report
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('admissions');
auth_check();

if (!is_super_admin() && !adm_can_edit()) {
    flash_set('error', 'You do not have permission to access Top Sheet settings.');
    redirect(APP_URL . '/admissions/top-sheet.php');
}

$page_title = 'Top Sheet Settings';

// ─────────────────────────────────────────────────────────────────────────────
// POST handlers
// ─────────────────────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

// ── Save general labels ───────────────────────────────────────────────────────
if ($action === 'save_general' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $sem_label  = trim($_POST['top_sheet_semester_label']  ?? '');
    $months     = trim($_POST['top_sheet_months']          ?? '');
    $adm_label  = trim($_POST['top_sheet_admission_label'] ?? '');

    if ($sem_label === '' || $adm_label === '') {
        flash_set('error', 'Semester label and admission label are required.');
    } else {
        adm_save_setting('top_sheet_semester_label',  $sem_label);
        adm_save_setting('top_sheet_months',          $months);
        adm_save_setting('top_sheet_admission_label', $adm_label);
        flash_set('success', 'General settings saved.');
    }
    redirect(APP_URL . '/admissions/top-sheet-settings.php?tab=general');
}

// ── Save a single program mapping row ─────────────────────────────────────────
if ($action === 'save_program' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $program_id  = (int)($_POST['program_id']  ?? 0);
    $short_label = trim($_POST['short_label']  ?? '');
    $full_name   = trim($_POST['full_name']     ?? '');
    $sort_order  = (int)($_POST['sort_order']  ?? 0);
    $is_visible  = isset($_POST['is_visible']) ? 1 : 0;

    if ($program_id <= 0 || $short_label === '') {
        flash_set('error', 'Program and short label are required.');
    } else {
        try {
            db()->prepare(
                'INSERT INTO admissions_top_sheet_programs
                    (program_id, short_label, full_name, sort_order, is_visible)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    short_label = VALUES(short_label),
                    full_name   = VALUES(full_name),
                    sort_order  = VALUES(sort_order),
                    is_visible  = VALUES(is_visible)'
            )->execute([$program_id, $short_label, $full_name, $sort_order, $is_visible]);
            flash_set('success', 'Program label saved.');
        } catch (\Throwable $e) {
            flash_set('error', 'Could not save: ' . $e->getMessage());
        }
    }
    redirect(APP_URL . '/admissions/top-sheet-settings.php?tab=programs');
}

// ── Delete a program mapping ───────────────────────────────────────────────────
if ($action === 'delete_program' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $program_id = (int)($_POST['program_id'] ?? 0);
    if ($program_id > 0) {
        try {
            db()->prepare(
                'DELETE FROM admissions_top_sheet_programs WHERE program_id = ?'
            )->execute([$program_id]);
            flash_set('success', 'Program label removed.');
        } catch (\Throwable $e) {
            flash_set('error', 'Could not delete: ' . $e->getMessage());
        }
    }
    redirect(APP_URL . '/admissions/top-sheet-settings.php?tab=programs');
}

// ─────────────────────────────────────────────────────────────────────────────
// Load data for display
// ─────────────────────────────────────────────────────────────────────────────

$tab = $_GET['tab'] ?? 'general';

// General settings
$sem_label  = adm_get_setting('top_sheet_semester_label',  'Summer Semester 2026');
$months     = adm_get_setting('top_sheet_months',           '4');
$adm_label  = adm_get_setting('top_sheet_admission_label', 'Admission in Summer 2026');

// All programs from DB
$all_programs = db()->query(
    'SELECT p.id, p.program_name, d.name AS dept_name
     FROM dept_academic_programs p
     LEFT JOIN dept_departments d ON d.id = p.dept_id
     WHERE p.is_active = 1
     ORDER BY d.name ASC, p.program_name ASC'
)->fetchAll();

// Existing mappings keyed by program_id
$existing = [];
try {
    $rows = db()->query(
        'SELECT * FROM admissions_top_sheet_programs ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
    foreach ($rows as $r) {
        $existing[(int)$r['program_id']] = $r;
    }
} catch (\Throwable $e) { /* table not yet created */ }

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-cog me-2 text-primary"></i>Top Sheet Settings
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/top-sheet.php">Top Sheet</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/admissions/top-sheet.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Top Sheet
    </a>
</div>

<?php flash_show(); ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'general'  ? 'active' : '' ?>"
           href="?tab=general">
            <i class="fas fa-sliders-h me-1"></i> General Labels
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'programs' ? 'active' : '' ?>"
           href="?tab=programs">
            <i class="fas fa-graduation-cap me-1"></i> Program Labels
        </a>
    </li>
</ul>

<?php if ($tab === 'general'): ?>
<!-- ── General Labels tab ── -->
<div class="card border-0 shadow-sm" style="max-width:560px;">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold">Report Label Settings</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= APP_URL ?>/admissions/top-sheet-settings.php?tab=general">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_general">

            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Semester Label
                    <small class="text-muted fw-normal ms-1">shown in the report header</small>
                </label>
                <input type="text" name="top_sheet_semester_label"
                       class="form-control"
                       value="<?= h($sem_label) ?>"
                       placeholder="e.g. Summer Semester 2026" required>
                <div class="form-text">Example: <em>Summer Semester 2026</em>, <em>Spring Semester 2027</em></div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Semester Duration (months)
                </label>
                <input type="number" name="top_sheet_months"
                       class="form-control" style="max-width:120px;"
                       value="<?= h($months) ?>" min="1" max="12"
                       placeholder="4">
                <div class="form-text">Displayed as the semester length in the printed report.</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Admission Period Label
                    <small class="text-muted fw-normal ms-1">column heading in the report</small>
                </label>
                <input type="text" name="top_sheet_admission_label"
                       class="form-control"
                       value="<?= h($adm_label) ?>"
                       placeholder="e.g. Admission in Summer 2026" required>
                <div class="form-text">Example: <em>Admission in Summer 2026</em></div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Program Labels tab ── -->
<div class="row g-4">

    <!-- Left: existing mappings -->
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">Mapped Programs</h6>
                <small class="text-muted"><?= count($existing) ?> configured</small>
            </div>
            <?php if (empty($existing)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-graduation-cap fa-2x mb-2 d-block"></i>
                No program labels configured yet.<br>
                Use the form on the right to add one.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Sort</th>
                            <th>Program</th>
                            <th>Label</th>
                            <th>Full Name</th>
                            <th>Visible</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Sort by sort_order
                    $sorted = $existing;
                    usort($sorted, fn($a, $b) => (int)$a['sort_order'] <=> (int)$b['sort_order']);
                    foreach ($sorted as $m):
                        $prog = null;
                        foreach ($all_programs as $p) {
                            if ((int)$p['id'] === (int)$m['program_id']) { $prog = $p; break; }
                        }
                    ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= (int)$m['sort_order'] ?></td>
                        <td>
                            <div class="fw-medium"><?= h($prog['program_name'] ?? '(#' . $m['program_id'] . ')') ?></div>
                            <?php if ($prog): ?>
                            <div class="text-muted" style="font-size:.75rem"><?= h($prog['dept_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary"><?= h($m['short_label']) ?></span></td>
                        <td class="text-muted" style="max-width:180px; white-space:normal">
                            <?= $m['full_name'] ? h($m['full_name']) : '<em class="text-muted">—</em>' ?>
                        </td>
                        <td>
                            <?= $m['is_visible']
                                ? '<span class="badge bg-success">Yes</span>'
                                : '<span class="badge bg-secondary">No</span>' ?>
                        </td>
                        <td class="text-end pe-3">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    title="Edit"
                                    onclick="loadEdit(<?= $m['program_id'] ?>,
                                        <?= json_encode($m['short_label']) ?>,
                                        <?= json_encode($m['full_name'] ?? '') ?>,
                                        <?= (int)$m['sort_order'] ?>,
                                        <?= (int)$m['is_visible'] ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Remove this label mapping?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_program">
                                <input type="hidden" name="program_id" value="<?= $m['program_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: add/edit form -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold" id="formHeading">Add Program Label</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= APP_URL ?>/admissions/top-sheet-settings.php?tab=programs"
                      id="programForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_program">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Program</label>
                        <select name="program_id" id="programSelect" class="form-select form-select-sm" required>
                            <option value="">— Select Program —</option>
                            <?php
                            $last_dept = null;
                            foreach ($all_programs as $p):
                                if ($p['dept_name'] !== $last_dept):
                                    if ($last_dept !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . h($p['dept_name']) . '">';
                                    $last_dept = $p['dept_name'];
                                endif;
                            ?>
                                <option value="<?= $p['id'] ?>"><?= h($p['program_name']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($last_dept !== null) echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Short Label
                            <small class="text-muted fw-normal ms-1">shown in report table</small>
                        </label>
                        <input type="text" name="short_label" id="shortLabel"
                               class="form-control form-control-sm"
                               placeholder="e.g. BBA, MBA 69 cr., CSE (Regular)" required>
                        <div class="form-text">Keep it concise — 1–3 words ideal.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Full Degree Name
                            <small class="text-muted fw-normal ms-1">shown in legend (optional)</small>
                        </label>
                        <input type="text" name="full_name" id="fullName"
                               class="form-control form-control-sm"
                               placeholder="e.g. Bachelor of Business Administration (BBA)- 4 Years">
                        <div class="form-text">Appears below the report table as a legend entry.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" name="sort_order" id="sortOrder"
                                   class="form-control form-control-sm"
                                   value="0" min="0" max="9999">
                            <div class="form-text">Lower = higher up in report.</div>
                        </div>
                        <div class="col-7 d-flex align-items-end pb-1">
                            <div class="form-check ms-2">
                                <input class="form-check-input" type="checkbox"
                                       name="is_visible" id="isVisible" checked>
                                <label class="form-check-label fw-semibold" for="isVisible">
                                    Show in report
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save me-1"></i> Save
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="resetForm()">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tip box -->
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body py-3 px-3">
                <p class="small fw-semibold mb-1 text-primary">
                    <i class="fas fa-lightbulb me-1"></i> Tips
                </p>
                <ul class="small text-muted mb-0 ps-3">
                    <li>Use <strong>Sort Order</strong> to control the row order in the printed report.</li>
                    <li>Set a <strong>Full Degree Name</strong> to add it to the legend (e.g. BBA = Bachelor of Business Administration).</li>
                    <li>Uncheck <strong>Show in report</strong> to hide a program without deleting its mapping.</li>
                    <li>Programs with no mapping still appear in the report using their database name.</li>
                </ul>
            </div>
        </div>
    </div>

</div><!-- .row -->
<?php endif; ?>

<script>
function loadEdit(programId, shortLabel, fullName, sortOrder, isVisible) {
    document.getElementById('formHeading').textContent = 'Edit Program Label';
    document.getElementById('programSelect').value = programId;
    document.getElementById('shortLabel').value    = shortLabel;
    document.getElementById('fullName').value      = fullName;
    document.getElementById('sortOrder').value     = sortOrder;
    document.getElementById('isVisible').checked   = isVisible === 1;
    document.getElementById('programForm').scrollIntoView({behavior: 'smooth'});
}

function resetForm() {
    document.getElementById('formHeading').textContent = 'Add Program Label';
    document.getElementById('programForm').reset();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
