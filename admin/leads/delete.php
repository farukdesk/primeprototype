<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

if (!leads_can_delete()) {
    flash_set('error', 'You do not have permission to delete leads.');
    redirect(APP_URL . '/leads/index.php');
}

$id   = (int)($_GET['id'] ?? 0);
$lead = leads_get($id);
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
    log_change('leads', 'DELETE', $id,
        $lead['first_name'] . ' ' . $lead['last_name'],
        null, $lead['lead_number'], null, 'Lead deleted by ' . $user['full_name']);
    flash_set('success', 'Lead ' . $lead['lead_number'] . ' deleted.');
    redirect(APP_URL . '/leads/index.php');
}

$page_title = 'Delete Lead';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-5">
                <i class="fas fa-exclamation-triangle text-danger fa-3x mb-3"></i>
                <h5 class="fw-semibold">Delete Lead?</h5>
                <p class="text-muted">You are about to permanently delete lead <strong><?= h($lead['lead_number']) ?></strong> – <strong><?= h($lead['first_name'] . ' ' . $lead['last_name']) ?></strong>. All notes, history, assignments and appointments will also be deleted. This action cannot be undone.</p>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger me-2"><i class="fas fa-trash me-1"></i> Yes, Delete</button>
                </form>
                <a href="<?= APP_URL ?>/leads/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
