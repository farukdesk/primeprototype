<?php
/**
 * Partial: Chain create/edit form.
 * Required vars: $departments, $user_groups, $init_steps_json, $init_dept_id, $init_prog_id
 * $_POST['name'], description, is_active pre-filled by calling page.
 */
$is_edit    = isset($chain) && $chain;
$form_action = $is_edit
    ? APP_URL . '/results/chains/edit.php?id=' . $chain['id']
    : APP_URL . '/results/chains/create.php';
?>

<form method="POST" action="<?= $form_action ?>" id="chainForm">
    <?= csrf_field() ?>
    <!-- Steps JSON submitted as hidden field, populated by JS -->
    <input type="hidden" name="steps_json" id="steps_json_input">

    <div class="row g-4">

        <!-- Left: Chain Info -->
        <div class="col-lg-5">
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-link me-2 text-muted"></i>Chain Details</h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Chain Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="200"
                               value="<?= h($_POST['name'] ?? '') ?>"
                               placeholder="e.g. BBA Standard Approval Chain">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="2" maxlength="1000"
                                  placeholder="Brief description…"><?= h($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Department Scope</label>
                        <select name="dept_id" id="chain_dept" class="form-select">
                            <option value="">— Global (all departments) —</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)$init_dept_id === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Leave blank to apply this chain to all departments.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Program Scope</label>
                        <select name="program_id" id="chain_prog" class="form-select"
                                <?= $init_dept_id ? '' : 'disabled' ?>>
                            <option value="">— All Programs in Department —</option>
                        </select>
                        <div class="form-text">Leave blank to apply to all programs in the department.</div>
                    </div>

                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="chain_active"
                               value="1" <?= !empty($_POST['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chain_active">Chain is Active</label>
                    </div>

                </div>
            </div>

            <!-- Actions -->
            <div class="card" style="border-radius:12px;">
                <div class="card-body p-4">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> <?= $is_edit ? 'Save Changes' : 'Create Chain' ?>
                        </button>
                        <a href="<?= APP_URL ?>/results/chains/index.php" class="btn btn-light" style="border-radius:10px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Steps Builder -->
        <div class="col-lg-7">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-muted"></i>Approval Steps</h6>
                    <button type="button" id="btn_add_step" class="btn btn-sm btn-outline-success" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i> Add Step
                    </button>
                </div>
                <div class="card-body p-3">

                    <div class="alert alert-secondary py-2 mb-3" style="font-size:.82rem;">
                        <i class="fas fa-lightbulb me-1 text-warning"></i>
                        Steps run top-to-bottom. Mark the <strong>first step</strong> as <em>Entry</em> (who submits)
                        and the <strong>last step</strong> as <em>Final</em> (who publishes). Use arrows to reorder.
                    </div>

                    <div id="steps_container">
                        <!-- Steps injected by JS -->
                    </div>

                    <div id="steps_empty" class="text-center text-muted py-3" style="display:none;">
                        No steps yet. Click <strong>Add Step</strong> to begin.
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<!-- Step row template -->
<template id="step_template">
    <div class="step-row card mb-2 border" style="border-radius:10px;">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Drag handle / order indicator -->
                <span class="step-num badge bg-light text-dark border fw-semibold" style="min-width:2rem; text-align:center;">1</span>

                <!-- Label -->
                <input type="text" class="form-control form-control-sm step-label flex-fill"
                       placeholder="Step label, e.g. Course Teacher" maxlength="200" required>

                <!-- Group -->
                <select class="form-select form-select-sm step-group" style="min-width:160px;" required>
                    <option value="">— User Group —</option>
                    <?php foreach ($user_groups as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Entry / Final flags -->
                <div class="d-flex align-items-center gap-3 ms-1">
                    <div class="form-check mb-0" title="Mark as Entry: this group submits the sheet">
                        <input type="checkbox" class="form-check-input step-entry" id="">
                        <label class="form-check-label small text-success fw-medium">Entry</label>
                    </div>
                    <div class="form-check mb-0" title="Mark as Final: this group publishes">
                        <input type="checkbox" class="form-check-input step-final" id="">
                        <label class="form-check-label small text-danger fw-medium">Final</label>
                    </div>
                </div>

                <!-- Move up / down / delete -->
                <div class="d-flex gap-1 ms-auto">
                    <button type="button" class="btn btn-xs btn-outline-secondary btn-move-up"
                            style="border-radius:6px; padding:2px 7px;" title="Move up">
                        <i class="fas fa-chevron-up" style="font-size:.7rem;"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-secondary btn-move-down"
                            style="border-radius:6px; padding:2px 7px;" title="Move down">
                        <i class="fas fa-chevron-down" style="font-size:.7rem;"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-danger btn-remove-step"
                            style="border-radius:6px; padding:2px 7px;" title="Remove">
                        <i class="fas fa-times" style="font-size:.7rem;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    var APP_URL     = '<?= APP_URL ?>';
    var deptSel     = document.getElementById('chain_dept');
    var progSel     = document.getElementById('chain_prog');
    var container   = document.getElementById('steps_container');
    var emptyMsg    = document.getElementById('steps_empty');
    var btnAdd      = document.getElementById('btn_add_step');
    var template    = document.getElementById('step_template');
    var stepsInput  = document.getElementById('steps_json_input');
    var form        = document.getElementById('chainForm');

    var savedProgId = <?= (int)$init_prog_id ?>;

    // ── Department → Program loader ───────────────────────────────────────────
    function loadPrograms(deptId, selectId) {
        progSel.innerHTML = '<option value="">— All Programs in Department —</option>';
        progSel.disabled  = !deptId;
        if (!deptId) return;
        fetch(APP_URL + '/results/get-programs.php?dept_id=' + deptId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                data.forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = p.program_name;
                    if (p.id == selectId) o.selected = true;
                    progSel.appendChild(o);
                });
            });
    }
    deptSel.addEventListener('change', function () { loadPrograms(this.value, 0); });
    if (deptSel.value) loadPrograms(deptSel.value, savedProgId);

    // ── Step management ───────────────────────────────────────────────────────
    function renumber() {
        var rows = container.querySelectorAll('.step-row');
        rows.forEach(function (r, i) { r.querySelector('.step-num').textContent = i + 1; });
        emptyMsg.style.display = rows.length === 0 ? '' : 'none';
    }

    function wireRow(row) {
        row.querySelector('.btn-remove-step').addEventListener('click', function () {
            row.remove(); renumber();
        });
        row.querySelector('.btn-move-up').addEventListener('click', function () {
            var prev = row.previousElementSibling;
            if (prev && prev.classList.contains('step-row')) {
                container.insertBefore(row, prev);
                renumber();
            }
        });
        row.querySelector('.btn-move-down').addEventListener('click', function () {
            var next = row.nextElementSibling;
            if (next && next.classList.contains('step-row')) {
                container.insertBefore(next, row);
                renumber();
            }
        });
        // Only one entry + one final
        row.querySelector('.step-entry').addEventListener('change', function () {
            if (this.checked) {
                container.querySelectorAll('.step-entry').forEach(function (cb) {
                    if (cb !== this) cb.checked = false;
                }, this);
            }
        });
        row.querySelector('.step-final').addEventListener('change', function () {
            if (this.checked) {
                container.querySelectorAll('.step-final').forEach(function (cb) {
                    if (cb !== this) cb.checked = false;
                }, this);
            }
        });
    }

    function addStep(data) {
        var clone = template.content.cloneNode(true);
        var row   = clone.querySelector('.step-row');
        if (data) {
            row.querySelector('.step-label').value = data.label || '';
            var grpSel = row.querySelector('.step-group');
            if (data.group_id) {
                for (var i = 0; i < grpSel.options.length; i++) {
                    if (grpSel.options[i].value == data.group_id) {
                        grpSel.selectedIndex = i; break;
                    }
                }
            }
            if (data.is_entry) row.querySelector('.step-entry').checked = true;
            if (data.is_final) row.querySelector('.step-final').checked = true;
        }
        wireRow(row);
        container.appendChild(row);
        renumber();
    }

    btnAdd.addEventListener('click', function () { addStep(null); });

    // Load initial steps from JSON
    var initSteps = <?= $init_steps_json ?>;
    if (Array.isArray(initSteps) && initSteps.length > 0) {
        initSteps.forEach(function (s) { addStep(s); });
    } else {
        renumber();
    }

    // On submit: collect steps into JSON
    form.addEventListener('submit', function (e) {
        var rows  = container.querySelectorAll('.step-row');
        var steps = [];
        var valid = true;
        rows.forEach(function (row) {
            var label    = row.querySelector('.step-label').value.trim();
            var group_id = row.querySelector('.step-group').value;
            var is_entry = row.querySelector('.step-entry').checked;
            var is_final = row.querySelector('.step-final').checked;
            if (!label || !group_id) { valid = false; }
            steps.push({ label: label, group_id: group_id, is_entry: is_entry, is_final: is_final });
        });
        if (!valid) { alert('All steps must have a label and a user group.'); e.preventDefault(); return; }
        if (steps.length < 2) { alert('A chain must have at least 2 steps.'); e.preventDefault(); return; }
        var hasEntry = steps.some(function (s) { return s.is_entry; });
        var hasFinal = steps.some(function (s) { return s.is_final; });
        if (!hasEntry) { alert('Please mark one step as the Entry step.'); e.preventDefault(); return; }
        if (!hasFinal) { alert('Please mark one step as the Final (publish) step.'); e.preventDefault(); return; }
        stepsInput.value = JSON.stringify(steps);
    });
})();
</script>
