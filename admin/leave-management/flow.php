<?php
/**
 * Admin: configure the ordered, multi-user-group approval flow for leave.
 *
 * The flow is scoped per requester user group: first pick the user group whose
 * members are requesting leave (the department / staff group), then define the
 * ordered approving steps for that group. Different requester groups can
 * therefore have completely different approval systems.
 *
 * Note: changing the flow only affects requests submitted afterwards; each
 * request snapshots the flow at submission time.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('leave-management', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Leave Approval Flow';
$db         = db();
$groups     = lm_group_options();

/** Validate that a group id is a selectable (active, non-super) user group. */
$valid_group = function (int $gid) use ($db): bool {
    if ($gid < 1) return false;
    $chk = $db->prepare('SELECT id FROM user_groups WHERE id = ? AND is_active = 1 AND is_super = 0');
    $chk->execute([$gid]);
    return (bool)$chk->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $req_gid = (int)($_POST['requester_group_id'] ?? 0);

    if (!$valid_group($req_gid)) {
        flash_set('error', 'Please choose a valid requester user group first.');
        redirect(APP_URL . '/leave-management/flow.php');
    }

    if ($action === 'add') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $label    = trim($_POST['label'] ?? '');
        if (!$valid_group($group_id)) {
            flash_set('error', 'Please choose a valid approving user group.');
        } else {
            $nstmt = $db->prepare('SELECT COALESCE(MAX(step_order),0) + 1 FROM leave_approval_flow WHERE requester_group_id = ?');
            $nstmt->execute([$req_gid]);
            $next = (int)$nstmt->fetchColumn();
            $db->prepare('INSERT INTO leave_approval_flow (requester_group_id, step_order, group_id, label, is_active) VALUES (?,?,?,?,1)')
               ->execute([$req_gid, $next, $group_id, $label !== '' ? $label : null]);
            log_change('leave-management', 'CREATE', $group_id, 'Approval step ' . $next . ' for group ' . $req_gid);
            flash_set('success', 'Approval step added.');
        }
    } elseif ($action === 'delete') {
        $sid = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM leave_approval_flow WHERE id = ? AND requester_group_id = ?')->execute([$sid, $req_gid]);
        // Re-sequence remaining steps within this requester group to stay contiguous.
        $rstmt = $db->prepare('SELECT id FROM leave_approval_flow WHERE requester_group_id = ? ORDER BY step_order ASC, id ASC');
        $rstmt->execute([$req_gid]);
        $rows = $rstmt->fetchAll();
        $i = 0;
        $upd = $db->prepare('UPDATE leave_approval_flow SET step_order = ? WHERE id = ?');
        foreach ($rows as $r) { $i++; $upd->execute([$i, (int)$r['id']]); }
        log_change('leave-management', 'DELETE', $sid, 'Approval step');
        flash_set('success', 'Approval step removed.');
    } elseif ($action === 'move') {
        $sid = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $cur = $db->prepare('SELECT id, step_order FROM leave_approval_flow WHERE id = ? AND requester_group_id = ?');
        $cur->execute([$sid, $req_gid]);
        $row = $cur->fetch();
        if ($row) {
            $cmp = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr = $db->prepare("SELECT id, step_order FROM leave_approval_flow WHERE requester_group_id = ? AND step_order $cmp ? ORDER BY step_order $ord LIMIT 1");
            $nbr->execute([$req_gid, (int)$row['step_order']]);
            $neighbor = $nbr->fetch();
            if ($neighbor) {
                $swap = $db->prepare('UPDATE leave_approval_flow SET step_order = ? WHERE id = ?');
                $swap->execute([(int)$neighbor['step_order'], (int)$row['id']]);
                $swap->execute([(int)$row['step_order'], (int)$neighbor['id']]);
            }
        }
        flash_set('success', 'Approval order updated.');
    } elseif ($action === 'toggle') {
        $sid = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE leave_approval_flow SET is_active = 1 - is_active WHERE id = ? AND requester_group_id = ?')->execute([$sid, $req_gid]);
        flash_set('success', 'Step status updated.');
    } elseif ($action === 'priority') {
        $priority = (int)($_POST['priority'] ?? LM_FLOW_DEFAULT_PRIORITY);
        if ($priority < 1 || $priority > 999) {
            flash_set('error', 'Seniority must be a number between 1 and 999.');
        } else {
            try {
                lm_set_flow_priority($req_gid, $priority);
                log_change('leave-management', 'UPDATE', $req_gid, 'Flow seniority set to ' . $priority);
                flash_set('success', 'Flow seniority updated.');
            } catch (Throwable $e) {
                flash_set('error', 'Could not save seniority — please run the leave-flow-priority-v1.sql migration first.');
            }
        }
    }

    redirect(APP_URL . '/leave-management/flow.php?group=' . $req_gid);
}

// Selected requester group (defaults to the first available group).
$sel_group = (int)($_GET['group'] ?? 0);
if (!$valid_group($sel_group)) {
    $sel_group = !empty($groups) ? (int)$groups[0]['id'] : 0;
}

// All configured flows, grouped by requester group, for the overview list.
$flows_by_group = [];
$frows = $db->query(
    'SELECT f.requester_group_id, rg.name AS requester_group_name,
            f.step_order, f.label, f.is_active, g.name AS group_name
       FROM leave_approval_flow f
       JOIN user_groups rg ON rg.id = f.requester_group_id
       JOIN user_groups g  ON g.id = f.group_id
      ORDER BY rg.name ASC, f.step_order ASC, f.id ASC'
)->fetchAll();
foreach ($frows as $fr) {
    $gid = (int)$fr['requester_group_id'];
    if (!isset($flows_by_group[$gid])) {
        $flows_by_group[$gid] = ['name' => $fr['requester_group_name'], 'steps' => []];
    }
    $flows_by_group[$gid]['steps'][] = $fr;
}

$steps = [];
if ($sel_group > 0) {
    $sstmt = $db->prepare(
        'SELECT f.*, g.name AS group_name
           FROM leave_approval_flow f
           JOIN user_groups g ON g.id = f.group_id
          WHERE f.requester_group_id = ?
          ORDER BY f.step_order ASC, f.id ASC'
    );
    $sstmt->execute([$sel_group]);
    $steps = $sstmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leave-management/index.php">Leave Management</a></li>
            <li class="breadcrumb-item active">Approval Flow</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-primary"></i>Configured Approval Flows</h6>
        <div style="min-width:260px;">
            <input type="text" id="flow-list-search" class="form-control form-control-sm" placeholder="Search by requester group...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="flow-list-table">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Requester Group</th>
                        <th>Approval Steps (in order)</th>
                        <th style="width:90px;">Steps</th>
                        <th style="width:160px;" title="Lower number = more senior. When a requester belongs to several groups with flows, the most senior group's flow is used.">Seniority <i class="fas fa-info-circle text-muted"></i></th>
                        <th style="width:110px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($flows_by_group)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No approval flows configured yet. Select a requester group below and add the first step.</td></tr>
                <?php else: foreach ($flows_by_group as $gid => $fg): ?>
                    <tr class="<?= $gid === $sel_group ? 'table-primary' : '' ?>">
                        <td class="px-3"><strong><?= h($fg['name']) ?></strong></td>
                        <td>
                            <?php foreach ($fg['steps'] as $st): ?>
                                <span class="badge <?= $st['is_active'] ? 'bg-primary' : 'bg-secondary' ?> me-1 mb-1" title="<?= $st['is_active'] ? 'Active' : 'Inactive' ?>"><?= (int)$st['step_order'] ?>. <?= h($st['group_name']) ?><?= $st['label'] ? ' – ' . h($st['label']) : '' ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td><?= count($fg['steps']) ?></td>
                        <td>
                            <form method="POST" class="d-flex align-items-center gap-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="priority">
                                <input type="hidden" name="requester_group_id" value="<?= $gid ?>">
                                <input type="number" name="priority" class="form-control form-control-sm" style="width:80px;"
                                       min="1" max="999" value="<?= lm_flow_priority($gid) ?>" title="Lower number = more senior">
                                <button class="btn btn-sm btn-outline-secondary" title="Save seniority" style="border-radius:7px;"><i class="fas fa-save"></i></button>
                            </form>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/leave-management/flow.php?group=<?= $gid ?>#flow-editor" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"><i class="fas fa-pen me-1"></i>Edit</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr id="flow-list-empty" class="d-none"><td colspan="5" class="text-center text-muted py-4">No groups match your search.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer py-2 px-4 small text-muted">
            <i class="fas fa-lightbulb me-1"></i>
            <strong>Seniority</strong> decides which flow applies when a requester belongs to several groups
            (e.g. a Head who is also Faculty): the group with the <strong>lowest</strong> number wins.
            Suggested scheme: Pro-VC = 10, Dean = 20, Head = 30, Faculty = 100.
        </div>
    </div>
</div>

<div class="card mb-4" style="border-radius:12px;" id="flow-editor">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label fw-semibold small mb-1">Requester User Group</label>
                <select name="group" class="form-select" data-searchable data-search-placeholder="Search requester group..." onchange="this.form.submit()">
                    <?php if (empty($groups)): ?>
                        <option value="">No user groups available</option>
                    <?php else: foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= $sel_group === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                    <?php endforeach; endif; ?>
                </select>
                <div class="form-text">Pick the group whose members request leave; each group has its own approval system.</div>
            </div>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-primary"></i>Approval Steps (in order)</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3" style="width:60px;">Step</th>
                                <th>Approving Group</th>
                                <th>Label</th>
                                <th>Status</th>
                                <th style="width:170px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($steps)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No steps configured for this group yet. Add the first approval group below.</td></tr>
                        <?php else: $n = 0; $count = count($steps); foreach ($steps as $s): $n++; ?>
                            <tr>
                                <td class="px-3"><span class="badge bg-primary"><?= (int)$s['step_order'] ?></span></td>
                                <td><strong><?= h($s['group_name']) ?></strong></td>
                                <td class="text-muted"><?= h($s['label'] ?: '—') ?></td>
                                <td>
                                    <?php if ($s['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="requester_group_id" value="<?= $sel_group ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="dir" value="up">
                                            <button class="btn btn-sm btn-outline-secondary" title="Move up" style="border-radius:7px;" <?= $n === 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-up"></i></button>
                                        </form>
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="requester_group_id" value="<?= $sel_group ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="dir" value="down">
                                            <button class="btn btn-sm btn-outline-secondary" title="Move down" style="border-radius:7px;" <?= $n === $count ? 'disabled' : '' ?>><i class="fas fa-arrow-down"></i></button>
                                        </form>
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="requester_group_id" value="<?= $sel_group ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-info" title="Toggle active" style="border-radius:7px;"><i class="fas fa-power-off"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Remove this approval step?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="requester_group_id" value="<?= $sel_group ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-muted"></i>Add Approval Step</h6></div>
            <div class="card-body p-4">
                <?php if ($sel_group < 1): ?>
                <p class="text-muted mb-0">Select a requester user group above to configure its approval steps.</p>
                <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="requester_group_id" value="<?= $sel_group ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Approving User Group</label>
                        <select name="group_id" class="form-select" data-searchable data-search-placeholder="Search approving group..." required>
                            <option value="">— Select a group —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Label <span class="text-muted">(optional)</span></label>
                        <input type="text" name="label" class="form-control" maxlength="120" placeholder="e.g. Head of Department">
                        <div class="form-text">Shown on the request timeline for this step.</div>
                    </div>
                    <button class="btn btn-primary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i> Add Step</button>
                </form>
                <?php endif; ?>
                <hr>
                <p class="text-muted small mb-0">
                    Steps are approved in order. A member of each group signs with their uploaded signature.
                    Changes apply to <strong>new</strong> requests only.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Group search: add a filter box above every group <select data-searchable>.
    document.querySelectorAll('select[data-searchable]').forEach(function (select) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm mb-1';
        input.placeholder = select.dataset.searchPlaceholder || 'Search group...';
        input.autocomplete = 'off';
        select.parentNode.insertBefore(input, select);

        var options = Array.prototype.slice.call(select.options);
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            options.forEach(function (opt) {
                var match = opt.value === '' || q === '' || opt.text.toLowerCase().indexOf(q) !== -1;
                opt.hidden = !match;
                opt.disabled = !match;
            });
        });
    });

    // Flow list search: filter the overview rows by requester group name.
    var listSearch = document.getElementById('flow-list-search');
    if (listSearch) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('#flow-list-table tbody tr')).filter(function (r) {
            return r.id !== 'flow-list-empty' && r.cells.length > 1;
        });
        var emptyRow = document.getElementById('flow-list-empty');
        listSearch.addEventListener('input', function () {
            var q = listSearch.value.trim().toLowerCase();
            var visible = 0;
            rows.forEach(function (r) {
                var name = (r.cells[0] ? r.cells[0].textContent : '').toLowerCase();
                var show = q === '' || name.indexOf(q) !== -1;
                r.classList.toggle('d-none', !show);
                if (show) visible++;
            });
            if (emptyRow) emptyRow.classList.toggle('d-none', visible !== 0 || rows.length === 0);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
