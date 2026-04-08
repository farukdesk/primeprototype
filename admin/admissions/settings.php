<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/helpers.php';

auth_check();

if (!is_super_admin() && !adm_can_edit()) {
    flash_set('error', 'You do not have permission to access admissions settings.');
    redirect(APP_URL . '/admissions/index.php');
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $next_num = trim($_POST['next_form_number'] ?? '');
    if ($next_num !== '' && ctype_digit($next_num) && (int)$next_num >= 1) {
        adm_save_setting('next_form_number', (string)(int)$next_num);
        flash_set('success', 'Settings saved successfully.');
    } else {
        flash_set('error', 'Form number must be a positive integer.');
    }
    redirect(APP_URL . '/admissions/settings.php?tab=general');
}

if ($action === 'save_mapping' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
        exit;
    }
    $field_key   = trim($_POST['field_key']   ?? '');
    $page_number = (int)($_POST['page_number'] ?? 0);
    $x_percent   = (float)($_POST['x_percent'] ?? 0);
    $y_percent   = (float)($_POST['y_percent'] ?? 0);
    $font_size   = max(6, min(24, (int)($_POST['font_size'] ?? 10)));

    if ($field_key === '' || !in_array($page_number, [1, 2], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    db()->prepare(
        'INSERT INTO admissions_field_mappings (field_key, page_number, x_percent, y_percent, font_size)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE x_percent=VALUES(x_percent), y_percent=VALUES(y_percent), font_size=VALUES(font_size)'
    )->execute([$field_key, $page_number, $x_percent, $y_percent, $font_size]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'remove_mapping' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
        exit;
    }
    $field_key   = trim($_POST['field_key']   ?? '');
    $page_number = (int)($_POST['page_number'] ?? 0);
    db()->prepare(
        'DELETE FROM admissions_field_mappings WHERE field_key = ? AND page_number = ?'
    )->execute([$field_key, $page_number]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $page_number = (int)($_POST['page_number'] ?? 0);
    if (!in_array($page_number, [1, 2], true)) {
        flash_set('error', 'Invalid page number.');
        redirect(APP_URL . '/admissions/settings.php?tab=templates');
    }

    if (empty($_FILES['template_file']['name'])) {
        flash_set('error', 'Please choose a file to upload.');
        redirect(APP_URL . '/admissions/settings.php?tab=templates');
    }

    $stored = adm_upload_template($_FILES['template_file']);
    if ($stored === false) {
        redirect(APP_URL . '/admissions/settings.php?tab=templates');
    }

    $ext      = strtolower(pathinfo($_FILES['template_file']['name'], PATHINFO_EXTENSION));
    $file_type = $ext === 'pdf' ? 'pdf' : 'image';
    $user      = auth_user();

    // Remove old stored file
    $old = adm_get_template($page_number);
    if ($old) {
        $old_path = UPLOAD_DIR . '/' . ADM_TPL_SUBDIR . '/' . $old['stored_file'];
        if (file_exists($old_path)) {
            unlink($old_path);
        }
    }

    db()->prepare(
        'INSERT INTO admissions_templates (page_number, original_name, stored_file, file_type, uploaded_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE original_name=VALUES(original_name), stored_file=VALUES(stored_file),
                                 file_type=VALUES(file_type), uploaded_at=NOW(), uploaded_by=VALUES(uploaded_by)'
    )->execute([
        $page_number,
        $_FILES['template_file']['name'],
        $stored,
        $file_type,
        $user['id'],
    ]);

    log_change('admissions', 'UPDATE', null, 'Template Page ' . $page_number, 'template_upload', null, $stored);
    flash_set('success', 'Template for Page ' . $page_number . ' uploaded successfully.');
    redirect(APP_URL . '/admissions/settings.php?tab=templates');
}

// ── Page data ─────────────────────────────────────────────────────────────────
$tpl1            = adm_get_template(1);
$tpl2            = adm_get_template(2);
$map1            = adm_get_mappings(1);
$map2            = adm_get_mappings(2);
$all_fields      = adm_get_all_fields();
$active_tab      = $_GET['tab'] ?? 'general';
$map_page        = (int)($_GET['map_page'] ?? 1);
if (!in_array($map_page, [1, 2], true)) $map_page = 1;
$next_form_number = adm_get_setting('next_form_number', '1');

$tpl_base_url = UPLOAD_URL . '/' . ADM_TPL_SUBDIR . '/';

$page_title = 'Admissions Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-primary"></i>Admissions Settings</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
</div>

<?php flash_show(); ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'general' ? 'active' : '' ?>" href="?tab=general">
            <i class="fas fa-sliders-h me-1"></i> General
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'templates' ? 'active' : '' ?>" href="?tab=templates">
            <i class="fas fa-image me-1"></i> Templates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'mapping' ? 'active' : '' ?>" href="?tab=mapping">
            <i class="fas fa-map-marked-alt me-1"></i> Field Mapping
        </a>
    </li>
</ul>

<?php if ($active_tab === 'general'): ?>
<!-- ══════════════════════════════════════════════════════════
     Tab A: General Settings
══════════════════════════════════════════════════════════ -->
<div class="row justify-content-center">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="fas fa-sliders-h me-2 text-primary"></i>General Settings
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Next Form Number</label>
                        <input type="number" name="next_form_number" class="form-control"
                               value="<?= h($next_form_number) ?>" min="1" step="1" required>
                        <div class="form-text">
                            The number that will be assigned to the <strong>next</strong> new application.
                            After each application is created, this counter increments automatically.
                            (e.g. enter <code>32433</code> and the next form will be numbered <code>32433</code>,
                            the one after that <code>32434</code>, and so on – no prefix.)
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($active_tab === 'templates'): ?>
<!-- ══════════════════════════════════════════════════════════
     Tab A: Template Upload
══════════════════════════════════════════════════════════ -->
<div class="row g-4">
    <?php foreach ([1 => $tpl1, 2 => $tpl2] as $pn => $tpl): ?>
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="fas fa-file-image me-2 text-info"></i>Page <?= $pn ?> (<?= $pn === 1 ? 'Front' : 'Back' ?>)
            </div>
            <div class="card-body">
                <!-- Current template preview -->
                <?php if ($tpl): ?>
                <div class="mb-3 text-center">
                    <?php if ($tpl['file_type'] === 'pdf'): ?>
                    <div class="border rounded p-3 bg-light text-muted">
                        <i class="fas fa-file-pdf fa-3x text-danger mb-2 d-block"></i>
                        <?= h($tpl['original_name']) ?><br>
                        <small>PDF – uploaded <?= h(date('d M Y', strtotime($tpl['uploaded_at']))) ?></small>
                    </div>
                    <?php else: ?>
                    <img src="<?= $tpl_base_url . h($tpl['stored_file']) ?>"
                         class="img-thumbnail" style="max-width:100%;max-height:300px" alt="Page <?= $pn ?> template">
                    <div class="text-muted small mt-1"><?= h($tpl['original_name']) ?> – <?= h(date('d M Y', strtotime($tpl['uploaded_at']))) ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="border rounded p-4 text-center text-muted bg-light mb-3" style="min-height:160px;display:flex;align-items:center;justify-content:center;flex-direction:column">
                    <i class="fas fa-image fa-3x mb-2"></i>
                    <div>No template uploaded</div>
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_template">
                    <input type="hidden" name="page_number" value="<?= $pn ?>">
                    <div class="mb-3">
                        <label class="form-label">Upload Template (max 20 MB)</label>
                        <input type="file" name="template_file" class="form-control"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                        <div class="form-text">JPG, PNG, GIF, WebP or PDF</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload me-1"></i> Upload Page <?= $pn ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════
     Tab B: Field Mapping
══════════════════════════════════════════════════════════ -->
<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $map_page === 1 ? 'active' : '' ?>" href="?tab=mapping&map_page=1">
            Page 1 (Front)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $map_page === 2 ? 'active' : '' ?>" href="?tab=mapping&map_page=2">
            Page 2 (Back)
        </a>
    </li>
</ul>

<?php
$cur_tpl = ($map_page === 1) ? $tpl1 : $tpl2;
$cur_map = ($map_page === 1) ? $map1 : $map2;

// Build a map of field_key → which page it's mapped to (for badge display)
$all_mapped = [];
foreach ($map1 as $key => $m) $all_mapped[$key] = 1;
foreach ($map2 as $key => $m) $all_mapped[$key] = isset($all_mapped[$key]) ? 'both' : 2;
?>

<div class="row g-4">
    <!-- Left: Image canvas -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-map-marker-alt me-2 text-danger"></i>Page <?= $map_page ?> Template</span>
                <div class="d-flex align-items-center gap-2">
                    <label class="mb-0 small">Font Size:</label>
                    <input type="number" id="fontSizeInput" class="form-control form-control-sm" value="10" min="6" max="24" style="width:65px">
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($cur_tpl && $cur_tpl['file_type'] !== 'pdf'): ?>
                <div id="imgContainer" style="position:relative;display:inline-block;cursor:crosshair;width:100%">
                    <img id="tplImage" src="<?= $tpl_base_url . h($cur_tpl['stored_file']) ?>"
                         style="display:block;width:100%;height:auto;user-select:none" alt="Template">
                    <!-- Existing mapped field labels will be injected by JS -->
                </div>
                <?php elseif ($cur_tpl && $cur_tpl['file_type'] === 'pdf'): ?>
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-file-pdf fa-4x text-danger mb-3 d-block"></i>
                    <p>This page uses a PDF template. Visual field placement on PDF is not supported.<br>
                       You can still set X/Y coordinates manually using the fields on the right.</p>
                </div>
                <?php else: ?>
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-image fa-4x mb-3 d-block"></i>
                    <p>No template uploaded for this page.<br>
                       <a href="?tab=templates">Upload a template first</a> to enable visual mapping.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($cur_tpl): ?>
            <div class="card-footer bg-white">
                <div id="statusMsg" class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    Select a field from the right panel, then click on the image to place it.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Field list -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="fas fa-list me-2 text-primary"></i>Fields
                <span class="badge bg-secondary ms-1 small" id="selectedFieldBadge" style="display:none"></span>
            </div>
            <div class="card-body p-0">
                <div style="max-height:600px;overflow-y:auto">
                    <?php foreach ($all_fields as $f):
                        $fk = $f['field_key'];
                        $is_mapped_here = isset($cur_map[$fk]);
                        $mapped_page    = $all_mapped[$fk] ?? null;
                    ?>
                    <div class="field-item d-flex align-items-center justify-content-between px-3 py-2 border-bottom"
                         data-field-key="<?= h($fk) ?>"
                         data-field-label="<?= h($f['field_label']) ?>"
                         style="cursor:pointer;transition:background .15s"
                         onmouseover="this.style.background='#f8f9fa'"
                         onmouseout="this.style.background=selectedField===this?'#e3f2fd':''">
                        <div>
                            <div class="small fw-semibold"><?= h($f['field_label']) ?></div>
                            <div class="text-muted" style="font-size:10px"><?= h($fk) ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <?php if ($is_mapped_here): ?>
                            <span class="badge bg-success" style="font-size:9px">P<?= $map_page ?></span>
                            <button type="button" class="btn btn-xs btn-outline-danger remove-mapping-btn"
                                    data-field-key="<?= h($fk) ?>" style="font-size:10px;padding:1px 5px" title="Remove mapping">
                                <i class="fas fa-times"></i>
                            </button>
                            <?php elseif ($mapped_page): ?>
                            <span class="badge bg-info" style="font-size:9px">P<?= $mapped_page ?></span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border" style="font-size:9px">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
// ── CSRF token ────────────────────────────────────────────────────────────────
var csrfToken = '<?= csrf_token() ?>';
var mapPage   = <?= $map_page ?>;
var tplBaseUrl = '<?= $tpl_base_url ?>';

// ── Existing mappings ─────────────────────────────────────────────────────────
var existingMappings = <?= json_encode(($active_tab === 'mapping') ? $cur_map : [], JSON_HEX_TAG) ?>;

// ── Field selection state ─────────────────────────────────────────────────────
var selectedField  = null; // DOM element
var selectedKey    = null;
var selectedLabel  = null;

// ── Render existing mapped labels on image ────────────────────────────────────
function renderOverlays() {
    var container = document.getElementById('imgContainer');
    if (!container) return;
    // Remove existing overlays
    container.querySelectorAll('.mapped-label').forEach(function(el) { el.remove(); });
    // Render from existingMappings
    for (var key in existingMappings) {
        var m = existingMappings[key];
        addOverlayLabel(container, key, m.field_key, m.x_percent, m.y_percent, m.font_size || 10);
    }
}

function addOverlayLabel(container, key, label, xPct, yPct, fontSize) {
    var div = document.createElement('div');
    div.className = 'mapped-label';
    div.dataset.fieldKey = key;
    div.style.cssText = 'position:absolute;left:' + xPct + '%;top:' + yPct + '%;'
                       + 'font-size:' + fontSize + 'pt;white-space:nowrap;line-height:1;'
                       + 'background:rgba(255,235,59,.75);border:1px solid #f9a825;'
                       + 'padding:1px 3px;border-radius:2px;cursor:pointer;font-family:Arial,sans-serif;color:#000;';
    div.title = label + ' (' + xPct.toFixed(1) + '%, ' + yPct.toFixed(1) + '%)';
    div.textContent = label;
    container.appendChild(div);
}

// ── Click on field in right panel ─────────────────────────────────────────────
document.querySelectorAll('.field-item').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target.closest('.remove-mapping-btn')) return;
        if (selectedField) selectedField.style.background = '';
        selectedField = el;
        selectedKey   = el.dataset.fieldKey;
        selectedLabel = el.dataset.fieldLabel;
        el.style.background = '#e3f2fd';
        var badge = document.getElementById('selectedFieldBadge');
        if (badge) {
            badge.textContent = selectedLabel;
            badge.style.display = '';
        }
        var msg = document.getElementById('statusMsg');
        if (msg) msg.innerHTML = '<i class="fas fa-crosshairs me-1 text-danger"></i>Placing: <strong>' + selectedLabel + '</strong> – click on image to set position.';
    });
});

// ── Click on image to place field ─────────────────────────────────────────────
var imgContainer = document.getElementById('imgContainer');
if (imgContainer) {
    imgContainer.addEventListener('click', function(e) {
        if (!selectedKey) {
            var msg = document.getElementById('statusMsg');
            if (msg) msg.innerHTML = '<i class="fas fa-exclamation-triangle me-1 text-warning"></i>Select a field from the right panel first.';
            return;
        }
        var rect = imgContainer.getBoundingClientRect();
        var x = ((e.clientX - rect.left) / rect.width)  * 100;
        var y = ((e.clientY - rect.top)  / rect.height) * 100;
        var fontSize = parseInt(document.getElementById('fontSizeInput').value) || 10;

        // Optimistic UI update
        existingMappings[selectedKey] = { field_key: selectedKey, x_percent: x, y_percent: y, font_size: fontSize };
        renderOverlays();

        // AJAX save
        var fd = new FormData();
        fd.append('action',      'save_mapping');
        fd.append('field_key',   selectedKey);
        fd.append('page_number', mapPage);
        fd.append('x_percent',   x.toFixed(3));
        fd.append('y_percent',   y.toFixed(3));
        fd.append('font_size',   fontSize);
        fd.append('<?= CSRF_TOKEN_NAME ?>', csrfToken);

        fetch('<?= APP_URL ?>/admissions/settings.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    var msg = document.getElementById('statusMsg');
                    if (msg) msg.innerHTML = '<i class="fas fa-check-circle me-1 text-success"></i><strong>' + selectedLabel + '</strong> placed at (' + x.toFixed(1) + '%, ' + y.toFixed(1) + '%).';
                    // Update badge in field list
                    updateFieldBadge(selectedKey, true);
                }
            })
            .catch(function() {});
    });
}

// ── Remove mapping buttons ────────────────────────────────────────────────────
document.querySelectorAll('.remove-mapping-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var key = btn.dataset.fieldKey;
        var fd = new FormData();
        fd.append('action',      'remove_mapping');
        fd.append('field_key',   key);
        fd.append('page_number', mapPage);
        fd.append('<?= CSRF_TOKEN_NAME ?>', csrfToken);

        fetch('<?= APP_URL ?>/admissions/settings.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    delete existingMappings[key];
                    renderOverlays();
                    updateFieldBadge(key, false);
                    var msg = document.getElementById('statusMsg');
                    if (msg) msg.innerHTML = '<i class="fas fa-trash me-1 text-muted"></i>Mapping removed for <strong>' + key + '</strong>.';
                }
            })
            .catch(function() {});
    });
});

function updateFieldBadge(fieldKey, isMapped) {
    var item = document.querySelector('[data-field-key="' + CSS.escape(fieldKey) + '"]');
    if (!item) return;
    var badgeArea = item.querySelector('.d-flex');
    if (!badgeArea) return;
    var existingBadge = badgeArea.querySelector('.badge');
    var existingRemove = badgeArea.querySelector('.remove-mapping-btn');
    if (isMapped) {
        if (!existingBadge) {
            var span = document.createElement('span');
            span.className = 'badge bg-success';
            span.style.fontSize = '9px';
            span.textContent = 'P' + mapPage;
            badgeArea.prepend(span);
        } else {
            existingBadge.className = 'badge bg-success';
            existingBadge.style.fontSize = '9px';
            existingBadge.textContent = 'P' + mapPage;
        }
        if (!existingRemove) {
            var btn2 = document.createElement('button');
            btn2.type = 'button';
            btn2.className = 'btn btn-xs btn-outline-danger remove-mapping-btn';
            btn2.dataset.fieldKey = fieldKey;
            btn2.style.cssText = 'font-size:10px;padding:1px 5px';
            btn2.title = 'Remove mapping';
            btn2.innerHTML = '<i class="fas fa-times"></i>';
            btn2.addEventListener('click', function(e) {
                e.stopPropagation();
                // Trigger via same logic
                var fd2 = new FormData();
                fd2.append('action', 'remove_mapping');
                fd2.append('field_key', fieldKey);
                fd2.append('page_number', mapPage);
                fd2.append('<?= CSRF_TOKEN_NAME ?>', csrfToken);
                fetch('<?= APP_URL ?>/admissions/settings.php', { method: 'POST', body: fd2 })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.ok) {
                            delete existingMappings[fieldKey];
                            renderOverlays();
                            updateFieldBadge(fieldKey, false);
                        }
                    });
            });
            badgeArea.appendChild(btn2);
        }
    } else {
        if (existingBadge) {
            existingBadge.className = 'badge bg-light text-muted border';
            existingBadge.style.fontSize = '9px';
            existingBadge.textContent = '—';
        }
        if (existingRemove) existingRemove.remove();
    }
}

// Init on page load
renderOverlays();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
