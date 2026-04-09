<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/form-sale-helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!adm_can_manage()) {
    flash_set('error', 'You do not have permission to sell forms.');
    redirect(APP_URL . '/admissions/form-sale-index.php');
}

$page_title = 'Sell Admission Form';
$user       = auth_user();
$errors     = [];

// ── Default form price from settings ──────────────────────────────────────────
$default_price = adm_fs_get_setting('form_price', '500');

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $buyer_name   = trim($_POST['buyer_name']   ?? '');
    $buyer_email  = trim($_POST['buyer_email']  ?? '') ?: null;
    $buyer_mobile = trim($_POST['buyer_mobile'] ?? '');
    $form_price   = trim($_POST['form_price']   ?? $default_price);

    if ($buyer_name === '')   $errors[] = 'Full name is required.';
    if ($buyer_mobile === '') $errors[] = 'Mobile number is required.';
    if (!is_numeric($form_price) || (float)$form_price < 0) {
        $errors[] = 'Form price must be a valid positive number.';
    }

    if (empty($errors)) {
        $form_number = adm_fs_generate_number();

        db()->prepare(
            'INSERT INTO adm_form_sales
               (form_number, buyer_name, buyer_email, buyer_mobile, form_price, sold_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $form_number,
            $buyer_name,
            $buyer_email,
            $buyer_mobile,
            (float)$form_price,
            $user['id'],
        ]);
        $sale_id = (int)db()->lastInsertId();

        log_change('admissions', 'CREATE', $sale_id, 'Form Sale ' . $form_number);

        // ── Notifications ─────────────────────────────────────────────────────
        $notif_vars = [
            'form_number'  => $form_number,
            'buyer_name'   => $buyer_name,
            'buyer_mobile' => $buyer_mobile,
            'form_price'   => number_format((float)$form_price, 2),
            'sold_date'    => date('d/m/Y'),
            'app_name'     => APP_NAME,
        ];

        // SMS notification
        if ($buyer_mobile !== '') {
            $sms_tpl = adm_get_setting('sms_template_form_sale', '');
            if ($sms_tpl !== '') {
                adm_send_sms($buyer_mobile, adm_render_template_string($sms_tpl, $notif_vars));
            }
        }

        // Email notification
        if ($buyer_email !== null && $buyer_email !== '') {
            send_template_email('form_sale_notification', $buyer_email, $buyer_name, $notif_vars);
        }

        flash_set('success', 'Form ' . h($form_number) . ' sold successfully. <a href="' . h(APP_URL . '/admissions/form-sale-print.php?id=' . $sale_id) . '" target="_blank" class="alert-link">Print Invoice</a>');
        redirect(APP_URL . '/admissions/form-sale-index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-receipt me-2 text-warning"></i>Sell Admission Form</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/form-sale-index.php">Form Sale</a></li>
            <li class="breadcrumb-item active">Sell Form</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/admissions/form-sale-index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="fas fa-money-bill-wave me-2 text-success"></i>Form Sale Details
            </div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <div class="alert alert-info small mb-4">
                        <i class="fas fa-info-circle me-1"></i>
                        A unique form number will be auto-generated. Collect the fee and fill in the buyer details below.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="buyer_name" class="form-control"
                               value="<?= h($_POST['buyer_name'] ?? '') ?>"
                               placeholder="Student / applicant full name" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                        <input type="text" name="buyer_mobile" class="form-control"
                               value="<?= h($_POST['buyer_mobile'] ?? '') ?>"
                               placeholder="+880 01…" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="email" name="buyer_email" class="form-control"
                               value="<?= h($_POST['buyer_email'] ?? '') ?>"
                               placeholder="student@example.com">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Form Price (Taka) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">৳</span>
                            <input type="number" name="form_price" class="form-control"
                                   value="<?= h($_POST['form_price'] ?? $default_price) ?>"
                                   min="0" step="0.01" required>
                        </div>
                        <div class="form-text">Default price is set in <a href="<?= APP_URL ?>/admissions/settings.php?tab=general">Admissions Settings → General</a>.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning text-dark fw-semibold">
                            <i class="fas fa-check me-1"></i> Sell &amp; Generate Form Number
                        </button>
                        <a href="<?= APP_URL ?>/admissions/form-sale-index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
