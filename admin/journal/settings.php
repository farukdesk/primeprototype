<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';
csrf_check();

$allowed = [
    'publisher_name' => 'Default Publisher Name',
    'gs_language'    => 'Metadata Language Code (e.g. en)',
    'archive_intro'  => 'Public Archive Page Intro Text',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        jm_require_write();
        foreach (array_keys($allowed) as $key) {
            jm_save_setting($key, trim($_POST[$key] ?? ''));
        }
        flash_set('success', 'Settings saved.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_URL . '/journal/settings.php');
}

$page_title = 'Journal Settings';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/journal/index.php">Journal Management</a></li>
        <li class="breadcrumb-item active">Settings</li>
    </ol></nav>
</div>

<?php flash_show(); ?>

<div class="card border-0 shadow-sm" style="border-radius:12px; max-width:720px;">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label"><?= h($allowed['publisher_name']) ?></label>
                <input class="form-control" name="publisher_name" value="<?= h(jm_setting('publisher_name', 'Prime University')) ?>">
                <div class="form-text">Used as <code>citation_publisher</code> when a journal has no publisher set.</div></div>
            <div class="mb-3"><label class="form-label"><?= h($allowed['gs_language']) ?></label>
                <input class="form-control" name="gs_language" maxlength="10" value="<?= h(jm_setting('gs_language', 'en')) ?>">
                <div class="form-text">Used as <code>citation_language</code> on public article pages.</div></div>
            <div class="mb-3"><label class="form-label"><?= h($allowed['archive_intro']) ?></label>
                <textarea class="form-control" rows="3" name="archive_intro"><?= h(jm_setting('archive_intro', '')) ?></textarea></div>
            <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
        </form>
    </div>
</div>

<div class="alert alert-light border mt-4" style="max-width:720px;">
    <div class="fw-semibold mb-1"><i class="fas fa-graduation-cap me-1"></i> Google Scholar checklist</div>
    <ul class="mb-0 small">
        <li>Every published article gets a permanent URL and embedded <code>citation_*</code> meta tags automatically.</li>
        <li>Upload a full-text PDF for each article – Scholar strongly prefers full text.</li>
        <li>Set ISSNs on each journal and a published date on each article.</li>
        <li>Once several issues are live, request inclusion via the Google Scholar Inclusions form.</li>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
