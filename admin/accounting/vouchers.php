<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$page_title = 'Vouchers';
$currency   = acc_currency();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['search']   ?? '');
$f_type    = $_GET['type']          ?? '';
$f_status  = $_GET['status']        ?? '';
$f_created_by = (int)($_GET['created_by'] ?? 0);
$f_student_sid = trim((string)($_GET['student_sid'] ?? ''));
$f_batch   = (int)($_GET['batch_id'] ?? 0);
$f_method  = $_GET['payment_method'] ?? '';
$f_from    = $_GET['date_from']     ?? '';
$f_to      = $_GET['date_to']       ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$valid_types = ['receipt','payment','contra','journal'];
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking', 'old_erp' => 'Old ERP'];

// Shared with voucher-delete.php so "select all filtered" resolves the exact
// same voucher set server-side (across every page, not just this one).
[$where_sql, $params] = acc_voucher_list_filter([
    'search'         => $search,
    'type'           => $f_type,
    'status'         => $f_status,
    'created_by'     => $f_created_by,
    'student_sid'    => $f_student_sid,
    'batch_id'       => $f_batch,
    'payment_method' => $f_method,
    'date_from'      => $f_from,
    'date_to'        => $f_to,
]);

$count_stmt = db()->prepare("SELECT COUNT(*) FROM acc_vouchers v $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = db()->prepare(
    "SELECT v.*, u.full_name AS created_by_name
     FROM acc_vouchers v
     LEFT JOIN users u ON u.id = v.created_by
     $where_sql
     ORDER BY v.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// Resolve business purpose (e.g. student fee) so we can show the linked student
// ID alongside the narration/reference for each voucher on this page.
$voucher_purposes = acc_get_voucher_purposes(array_map(static fn($v) => (int)$v['id'], $vouchers));

// Super Admins can bulk-select and delete multiple vouchers at once.
$can_bulk_delete = acc_can_delete_voucher_directly();

$created_by_stmt = db()->query(
    "SELECT u.id, u.full_name
     FROM users u
     WHERE EXISTS (
         SELECT 1 FROM acc_vouchers v
         WHERE v.created_by = u.id AND v.is_deleted = 0
     )
     ORDER BY u.full_name ASC"
);
$created_by_users = $created_by_stmt->fetchAll();

// University batches (students.batch_id → student_batches)
$batches_list = db()->query(
    'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC'
)->fetchAll();

$filter_qs = http_build_query(array_filter([
    'search'    => $search,
    'type'      => $f_type,
    'status'    => $f_status,
    'created_by'=> $f_created_by ?: null,
    'student_sid' => $f_student_sid,
    'batch_id'  => $f_batch ?: null,
    'payment_method' => $f_method,
    'date_from' => $f_from,
    'date_to'   => $f_to,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice me-2 text-primary"></i>Vouchers</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">All Vouchers</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($can_bulk_delete): ?>
        <button type="button" class="btn btn-outline-danger btn-sm js-bulk-delete-open" disabled
                data-bs-toggle="modal" data-bs-target="#voucherBulkDeleteModal">
            <i class="fas fa-trash-alt me-1"></i> Delete Selected (<span class="js-bulk-count">0</span>)
        </button>
        <?php endif; ?>
        <?php if (acc_can_access_voucher_delete()): ?>
        <a href="<?= APP_URL ?>/accounting/voucher-delete-requests.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash-restore me-1"></i> Delete Requests</a>
        <?php endif; ?>
        <?php if (acc_can_create()): ?>
        <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-success btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Collect Payment</a>
        <a href="<?= APP_URL ?>/accounting/add-expense.php"     class="btn btn-danger btn-sm"><i class="fas fa-receipt me-1"></i> Add Expense</a>
        <a href="<?= APP_URL ?>/accounting/transfer-money.php"  class="btn btn-info btn-sm text-white"><i class="fas fa-exchange-alt me-1"></i> Transfer</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Filter tabs by type -->
<ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php
    $tabs = ['' => 'All'] + array_combine($valid_types, ['Receipt','Payment','Transfer','Journal']);
    foreach ($tabs as $tv => $tl):
        $q = http_build_query(array_filter(array_merge(['search'=>$search,'status'=>$f_status,'created_by'=>$f_created_by ?: null,'student_sid'=>$f_student_sid,'batch_id'=>$f_batch ?: null,'payment_method'=>$f_method,'date_from'=>$f_from,'date_to'=>$f_to],['type'=>$tv])));
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $f_type === $tv ? 'active' : '' ?>" href="?<?= $q ?>"><?= h($tl) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-body">
        <!-- Filters -->
        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="type" value="<?= h($f_type) ?>">
            <div class="col-12 col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Voucher #, narration, reference…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <input type="text" name="student_sid" class="form-control form-control-sm"
                       placeholder="Student ID" value="<?= h($f_student_sid) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="batch_id" class="form-select form-select-sm">
                    <option value="0">All Batches</option>
                    <?php foreach ($batches_list as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Payment Methods</option>
                    <?php foreach ($pay_methods as $pmv => $pml): ?>
                    <option value="<?= h($pmv) ?>" <?= $f_method === $pmv ? 'selected' : '' ?>><?= h($pml) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="posted"   <?= $f_status === 'posted'   ? 'selected' : '' ?>>Posted</option>
                    <option value="reversed" <?= $f_status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                    <option value="memo"     <?= $f_status === 'memo'     ? 'selected' : '' ?>>Old ERP (not counted)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="created_by" class="form-select form-select-sm">
                    <option value="">All Creators</option>
                    <?php foreach ($created_by_users as $cu): ?>
                    <option value="<?= (int)$cu['id'] ?>" <?= $f_created_by === (int)$cu['id'] ? 'selected' : '' ?>><?= h($cu['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= h($f_from) ?>" placeholder="From date">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= h($f_to) ?>" placeholder="To date">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($vouchers)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-file-invoice fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No vouchers found.</p>
        </div>
        <?php else: ?>
        <?php if ($can_bulk_delete && $total_rows > count($vouchers)): ?>
        <div class="alert alert-info py-2 small d-none mb-2" id="vdAllFilteredBar" data-total="<?= (int)$total_rows ?>">
            <span id="vdAllFilteredOff">
                All <strong><?= count($vouchers) ?></strong> voucher(s) on this page are selected.
                <a href="#" id="vdSelectAllFiltered" class="fw-semibold">Select all <?= number_format($total_rows) ?> voucher(s) matching the current filter</a>
            </span>
            <span id="vdAllFilteredOn" class="d-none">
                All <strong><?= number_format($total_rows) ?></strong> voucher(s) matching the current filter are selected (across all pages).
                <a href="#" id="vdClearAllFiltered" class="fw-semibold">Clear selection</a>
            </span>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($can_bulk_delete): ?>
                        <th style="width:1%">
                            <input type="checkbox" class="form-check-input" id="vdSelectAll" title="Select all">
                        </th>
                        <?php endif; ?>
                        <th>Voucher #</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Narration / Reference</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vouchers as $v): ?>
                    <tr>
                        <?php if ($can_bulk_delete): ?>
                        <td>
                            <input type="checkbox" class="form-check-input js-voucher-check"
                                   form="bulkDeleteForm" name="ids[]" value="<?= (int)$v['id'] ?>">
                        </td>
                        <?php endif; ?>
                        <td>
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= h($v['voucher_number']) ?>
                            </a>
                            <?php if ($v['reversal_of']): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">REV</span>
                            <?php endif; ?>
                        </td>
                        <td><?= acc_voucher_type_badge($v['voucher_type']) ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($v['voucher_date'])) ?></td>
                        <td>
                            <div class="small"><?= h($v['narration'] ?? '–') ?></div>
                            <?php if ($v['reference']): ?><div class="text-muted" style="font-size:.72rem"><?= h($v['reference']) ?></div><?php endif; ?>
                            <?php
                            $vp  = $voucher_purposes[(int)$v['id']] ?? null;
                            $sid = '';
                            $sname = '';
                            if ($vp) {
                                $sid   = $vp['kind'] === 'admission_fee'
                                    ? ($vp['assigned_student_id'] ?? '')
                                    : ($vp['student_id'] ?? '');
                                $sname = $vp['student_name'] ?? '';
                            }
                            if ($sid !== ''):
                            ?>
                            <div style="font-size:.72rem">
                                <span class="badge bg-light text-dark border"><i class="fas fa-id-card me-1 text-primary"></i><?= h($sid) ?></span>
                                <?php if ($sname !== ''): ?><span class="text-muted ms-1"><?= h($sname) ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= $currency ?> <?= number_format($v['total_amount'], 2) ?></td>
                        <td><?= acc_voucher_status_badge($v['status']) ?></td>
                        <td class="text-muted small"><?= h($v['created_by_name'] ?? '–') ?></td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (($vp['kind'] ?? '') === 'student_fee' && $v['voucher_type'] === 'receipt'
                                      && $v['status'] !== 'reversed' && empty($v['is_deleted'])
                                      && (is_super_admin() || can_access('accounting', 'can_edit'))): ?>
                            <a href="<?= APP_URL ?>/accounting/voucher-move.php?id=<?= $v['id'] ?>"
                               class="btn btn-sm btn-outline-warning" title="Move to another category"><i class="fas fa-people-arrows"></i></a>
                            <?php endif; ?>
                            <?php if (acc_can_request_voucher_delete() && empty($v['is_deleted'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger js-voucher-delete"
                                    title="Delete"
                                    data-id="<?= $v['id'] ?>" data-number="<?= h($v['voucher_number']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#voucherDeleteModal">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav class="mt-3" aria-label="Voucher pages">
            <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                <?php
                // Windowed pagination: first/prev, a small window around the
                // current page, and last/next — avoids rendering ~90+ links.
                $window  = 2;
                $win_lo  = max(1, $page - $window);
                $win_hi  = min($total_pages, $page + $window);
                $pg_link = function (int $p) use ($filter_qs) {
                    return '?' . ($filter_qs !== '' ? $filter_qs . '&' : '') . 'page=' . $p;
                };
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $page <= 1 ? '#' : h($pg_link($page - 1)) ?>" aria-label="Previous">&laquo;</a>
                </li>
                <?php if ($win_lo > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= h($pg_link(1)) ?>">1</a></li>
                <?php if ($win_lo > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $win_lo; $p <= $win_hi; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= h($pg_link($p)) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($win_hi < $total_pages): ?>
                <?php if ($win_hi < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= h($pg_link($total_pages)) ?>"><?= $total_pages ?></a></li>
                <?php endif; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $page >= $total_pages ? '#' : h($pg_link($page + 1)) ?>" aria-label="Next">&raquo;</a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted small mt-2">Showing <?= count($vouchers) ?> of <?= number_format($total_rows) ?> voucher(s)</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (acc_can_request_voucher_delete()): ?>
<?php $vd_is_super = acc_can_delete_voucher_directly(); ?>
<!-- Voucher Delete Modal -->
<div class="modal fade" id="voucherDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/accounting/voucher-delete.php" enctype="multipart/form-data" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="vdVoucherId" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i><?= $vd_is_super ? 'Delete Voucher' : 'Request Voucher Deletion' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-<?= $vd_is_super ? 'danger' : 'warning' ?> small">
                    <?php if ($vd_is_super): ?>
                    Deleting <strong id="vdVoucherNum"></strong> clears the whole entry and its calculations from every report. This is logged permanently and cannot be undone.
                    <?php else: ?>
                    Your request for <strong id="vdVoucherNum"></strong> will be pending approval by <strong>DD Accounts</strong> and then the <strong>Treasurer</strong>.
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason for Deletion <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required minlength="10"
                              placeholder="Explain in detail why this voucher must be deleted…"></textarea>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Attachment <span class="text-muted small">(optional)</span></label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Allowed: pdf, jpg, png, doc, docx, xls, xlsx (max 5 MB).</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-<?= $vd_is_super ? 'danger' : 'warning' ?>">
                    <i class="fas fa-<?= $vd_is_super ? 'trash-alt' : 'paper-plane' ?> me-1"></i>
                    <?= $vd_is_super ? 'Delete Voucher' : 'Submit Request' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.js-voucher-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.dataset.id, num = btn.dataset.number;
        document.getElementById('vdVoucherId').value = id;
        document.querySelectorAll('#voucherDeleteModal #vdVoucherNum').forEach(function (el) {
            el.textContent = num;
        });
    });
});
</script>
<?php endif; ?>

<?php if ($can_bulk_delete): ?>
<!-- Bulk Voucher Delete Modal (Super Admin) -->
<div class="modal fade" id="voucherBulkDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/accounting/voucher-delete.php" enctype="multipart/form-data" class="modal-content" id="bulkDeleteForm">
            <?= csrf_field() ?>
            <input type="hidden" name="bulk" value="1">
            <input type="hidden" name="select_all_filtered" value="" id="vdSelectAllFilteredInput">
            <input type="hidden" name="f_search" value="<?= h($search) ?>">
            <input type="hidden" name="f_type" value="<?= h($f_type) ?>">
            <input type="hidden" name="f_status" value="<?= h($f_status) ?>">
            <input type="hidden" name="f_created_by" value="<?= (int)$f_created_by ?>">
            <input type="hidden" name="f_student_sid" value="<?= h($f_student_sid) ?>">
            <input type="hidden" name="f_batch_id" value="<?= (int)$f_batch ?>">
            <input type="hidden" name="f_payment_method" value="<?= h($f_method) ?>">
            <input type="hidden" name="f_date_from" value="<?= h($f_from) ?>">
            <input type="hidden" name="f_date_to" value="<?= h($f_to) ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i>Delete Selected Vouchers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger small">
                    You are about to delete <strong><span class="js-bulk-count">0</span></strong> selected voucher(s)<span id="vdBulkAllNote" class="d-none"> — <strong>every page</strong> matching the current filter, not just this page —</span> with one shared note.
                    This clears each entry and its calculations from every report. This is logged permanently and cannot be undone.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason for Deletion <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required minlength="10"
                              placeholder="Explain in detail why these vouchers must be deleted…"></textarea>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Attachment <span class="text-muted small">(optional)</span></label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Allowed: pdf, jpg, png, doc, docx, xls, xlsx (max 5 MB). Applied to all selected vouchers.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt me-1"></i> Delete <span class="js-bulk-count">0</span> Voucher(s)
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var selectAll = document.getElementById('vdSelectAll');
    var checks    = Array.prototype.slice.call(document.querySelectorAll('.js-voucher-check'));
    var bulkBtn   = document.querySelector('.js-bulk-delete-open');
    var countEls  = Array.prototype.slice.call(document.querySelectorAll('.js-bulk-count'));
    var bar       = document.getElementById('vdAllFilteredBar');
    var pickAll   = document.getElementById('vdSelectAllFiltered');
    var clearAll  = document.getElementById('vdClearAllFiltered');
    var stateOff  = document.getElementById('vdAllFilteredOff');
    var stateOn   = document.getElementById('vdAllFilteredOn');
    var safInput  = document.getElementById('vdSelectAllFilteredInput');
    var allNote   = document.getElementById('vdBulkAllNote');
    var totalRows = bar ? parseInt(bar.dataset.total || '0', 10) : 0;
    var allFiltered = false;

    function selectedCount() {
        return checks.filter(function (c) { return c.checked; }).length;
    }
    function refresh() {
        var pageCount = selectedCount();
        var n = allFiltered ? totalRows : pageCount;
        countEls.forEach(function (el) { el.textContent = n; });
        if (bulkBtn) { bulkBtn.disabled = n === 0; }
        if (selectAll) { selectAll.checked = pageCount > 0 && pageCount === checks.length; }
        if (safInput) { safInput.value = allFiltered ? '1' : ''; }
        if (allNote)  { allNote.classList.toggle('d-none', !allFiltered); }
        if (bar) {
            var allPage = checks.length > 0 && pageCount === checks.length;
            bar.classList.toggle('d-none', !(allPage || allFiltered));
            if (stateOff) { stateOff.classList.toggle('d-none', allFiltered); }
            if (stateOn)  { stateOn.classList.toggle('d-none', !allFiltered); }
        }
    }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = selectAll.checked; });
            if (!selectAll.checked) { allFiltered = false; }
            refresh();
        });
    }
    checks.forEach(function (c) {
        c.addEventListener('change', function () {
            allFiltered = false;
            refresh();
        });
    });
    if (pickAll) {
        pickAll.addEventListener('click', function (e) {
            e.preventDefault();
            allFiltered = true;
            refresh();
        });
    }
    if (clearAll) {
        clearAll.addEventListener('click', function (e) {
            e.preventDefault();
            allFiltered = false;
            checks.forEach(function (c) { c.checked = false; });
            if (selectAll) { selectAll.checked = false; }
            refresh();
        });
    }
    refresh();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>