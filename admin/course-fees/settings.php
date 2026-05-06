<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_edit');

$errors   = [];
$settings = cf_get_settings();
$db       = db();

$page_title = 'Course Fees – Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'settings';

    if ($action === 'settings') {
        $page_title_val  = trim($_POST['page_title']    ?? '');
        $session_label   = trim($_POST['session_label'] ?? '');
        $disclaimer      = trim($_POST['disclaimer']    ?? '');
        $is_published    = isset($_POST['is_published']) ? 1 : 0;
        $adm_fee_base    = max(0, (int)($_POST['admission_fee_base']   ?? 10000));
        $reg_fee_sem     = max(0, (int)($_POST['reg_fee_per_semester'] ?? 1000));
        $reg_fee_total   = max(0, (int)($_POST['reg_fee_total']        ?? 12000));
        $form_id_fee     = max(0, (int)($_POST['form_id_fee']          ?? 1000));
        $id_card_fee     = max(0, (int)($_POST['id_card_fee']          ?? 500));
        $admission_form_fee = max(0, (int)($_POST['admission_form_fee'] ?? 500));
        $bi_semester_start_month  = max(1, min(12, (int)($_POST['bi_semester_start_month']  ?? 1)));
        $tri_semester_start_month = max(1, min(12, (int)($_POST['tri_semester_start_month'] ?? 1)));

        if ($page_title_val === '') $errors[] = 'Page title is required.';
        if ($session_label  === '') $errors[] = 'Session label is required.';

        if (empty($errors)) {
            try {
                $db->prepare(
                    'UPDATE cf_settings SET page_title=?, session_label=?, disclaimer=?, is_published=?,
                     admission_fee_base=?, reg_fee_per_semester=?, reg_fee_total=?, form_id_fee=?,
                     id_card_fee=?, admission_form_fee=?, bi_semester_start_month=?, tri_semester_start_month=? WHERE id=1'
                )->execute([$page_title_val, $session_label, $disclaimer ?: null, $is_published,
                            $adm_fee_base, $reg_fee_sem, $reg_fee_total, $form_id_fee,
                            $id_card_fee, $admission_form_fee, $bi_semester_start_month, $tri_semester_start_month]);

                log_change('course-fees', 'UPDATE', 1, 'Settings', null, null, null, 'Global settings updated.');
                flash_set('success', 'Settings saved.');
                redirect(APP_URL . '/course-fees/settings.php');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'reg_fee_total') !== false
                    || strpos($e->getMessage(), 'id_card_fee') !== false
                    || strpos($e->getMessage(), 'admission_form_fee') !== false) {
                    $errors[] = 'Database migration required: please run <code>admin/course-fees-v4-extra-fees.sql</code> to add the new fee columns before saving.';
                } elseif (strpos($e->getMessage(), 'bi_semester_start_month') !== false
                          || strpos($e->getMessage(), 'tri_semester_start_month') !== false) {
                    $errors[] = 'Database migration required: please run <code>admin/course-fees-start-month-v2.sql</code> to add the bi/tri-semester start month columns before saving.';
                } else {
                    $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage());
                }
            }
        }

    } elseif ($action === 'degree_type_toggle') {
        $dt_id     = (int)($_POST['dt_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $db->prepare('UPDATE cf_degree_types SET is_active=? WHERE id=?')->execute([$is_active, $dt_id]);
        flash_set('success', 'Degree type updated.');
        redirect(APP_URL . '/course-fees/settings.php');
    }
}

$degree_types = cf_get_degree_types();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Course Fees Settings</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Global Settings -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-sliders me-2 text-primary"></i>Global Settings
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="settings">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page Title <span class="text-danger">*</span></label>
                        <input type="text" name="page_title"
                               value="<?= h($settings['page_title'] ?? 'Course Fee Calculator') ?>"
                               class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Session / Semester Label <span class="text-danger">*</span></label>
                        <input type="text" name="session_label"
                               value="<?= h($settings['session_label'] ?? 'Summer 2026') ?>"
                               class="form-control" placeholder="e.g. Summer 2026" required>
                        <div class="form-text">Shown as the badge on the public calculator page.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Disclaimer Text</label>
                        <textarea name="disclaimer" class="form-control" rows="4"><?= h($settings['disclaimer'] ?? '') ?></textarea>
                        <div class="form-text">Shown at the bottom of the public calculator page.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bi-Semester Start Month</label>
                        <select name="bi_semester_start_month" class="form-select">
                            <?php
                            $months = cf_get_months();
                            $bi_start_month = (int)($settings['bi_semester_start_month'] ?? $settings['start_month'] ?? 1);
                            foreach ($months as $num => $name):
                            ?>
                            <option value="<?= $num ?>" <?= $num === $bi_start_month ? 'selected' : '' ?>>
                                <?= h($name) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Starting month for bi-semester programs (2 semesters per year). Used to display month names in the student fee breakdown.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tri-Semester Start Month</label>
                        <select name="tri_semester_start_month" class="form-select">
                            <?php
                            $tri_start_month = (int)($settings['tri_semester_start_month'] ?? $settings['start_month'] ?? 1);
                            foreach ($months as $num => $name):
                            ?>
                            <option value="<?= $num ?>" <?= $num === $tri_start_month ? 'selected' : '' ?>>
                                <?= h($name) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Starting month for tri-semester programs (3 semesters per year). Used to display month names in the student fee breakdown.</div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_published" id="isPublished"
                                   value="1" <?= ($settings['is_published'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="isPublished">Published (visible to public)</label>
                        </div>
                    </div>
                    <hr>
                    <p class="fw-semibold text-muted small mb-3">Global Fee Constants (BDT)</p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Admission Fee (one-time)</label>
                            <input type="number" name="admission_fee_base" class="form-control form-control-sm"
                                   value="<?= (int)($settings['admission_fee_base'] ?? 10000) ?>" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Registration Fee / Semester</label>
                            <input type="number" name="reg_fee_per_semester" class="form-control form-control-sm"
                                   value="<?= (int)($settings['reg_fee_per_semester'] ?? 1000) ?>" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Registration Fees Total</label>
                            <input type="number" name="reg_fee_total" class="form-control form-control-sm"
                                   value="<?= (int)($settings['reg_fee_total'] ?? 12000) ?>" min="0" required>
                            <div class="form-text">Total registration fees across all semesters.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Admission Form + ID Card</label>
                            <input type="number" name="form_id_fee" class="form-control form-control-sm"
                                   value="<?= (int)($settings['form_id_fee'] ?? 1000) ?>" min="0" required>
                            <div class="form-text">One-time. Shown as "Extra" on the calculator.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">ID Card Fees</label>
                            <input type="number" name="id_card_fee" class="form-control form-control-sm"
                                   value="<?= (int)($settings['id_card_fee'] ?? 500) ?>" min="0" required>
                            <div class="form-text">One-time ID card fee.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Admission Form Fees</label>
                            <input type="number" name="admission_form_fee" class="form-control form-control-sm"
                                   value="<?= (int)($settings['admission_form_fee'] ?? 500) ?>" min="0" required>
                            <div class="form-text">One-time admission form fee.</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Settings
                        </button>
                        <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank" class="btn btn-outline-warning">
                            <i class="fas fa-external-link-alt me-1"></i> Preview Page
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Degree Type Management -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-layer-group me-2 text-info"></i>Degree Type Visibility
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Degree Type</th>
                            <th class="text-center">Visible</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($degree_types as $dt): ?>
                        <tr>
                            <td class="ps-3">
                                <span class="me-1"><?= h($dt['icon'] ?? '') ?></span>
                                <?= h($dt['name']) ?>
                                <div class="small text-muted"><?= h($dt['slug']) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($dt['is_active']): ?>
                                    <span class="badge bg-success">Visible</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Hidden</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-end">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"    value="degree_type_toggle">
                                    <input type="hidden" name="dt_id"     value="<?= $dt['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $dt['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= $dt['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                        <?= $dt['is_active'] ? 'Hide' : 'Show' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
