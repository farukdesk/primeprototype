<?php
/**
 * App Notification – Index (list + compose)
 * =========================================
 * Compose a push notification and view previously sent notifications.
 * Publishing queues the recipients (memory-safe for any audience size) and
 * this page polls process.php to deliver the queue in small chunks while
 * showing a live progress bar (?processing=ID).
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$page_title        = 'App Notification';
$can_send          = can_access('app-notifications', 'can_create');
$configured        = apn_fcm_is_configured();
$device_count      = apn_device_count();
$user_device_count = apn_user_device_count();
$groups            = apn_group_options();
$users             = apn_user_options();
$batches           = apn_batch_options();
$notifications     = apn_list(100);
$processing_id     = $can_send ? (int)($_GET['processing'] ?? 0) : 0;

// Reuse a previously sent notification (?reuse=ID): prefill the compose form.
$reuse_id = (int)($_GET['reuse'] ?? 0);
if ($reuse_id && $can_send) {
    $reuse = apn_find($reuse_id);
    if ($reuse) {
        save_old(['title' => $reuse['title'], 'body' => $reuse['body'], 'url' => (string)($reuse['url'] ?? '')]);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-mobile-alt me-2 text-primary"></i>App Notification</h1>
        <p class="text-muted small mb-0">Send a push notification to students using the mobile app.</p>
    </div>
    <a href="<?= APP_URL ?>/app-notifications/settings.php" class="btn btn-outline-secondary">
        <i class="fas fa-cog me-1"></i> FCM Settings
    </a>
</div>

<?php flash_show(); ?>

<?php if ($processing_id): ?>
<div class="card shadow-sm mb-4" id="apnProgressCard">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="fas fa-paper-plane text-primary"></i>
            <strong>Delivering notification…</strong>
            <span class="text-muted small">Keep this page open until delivery completes.</span>
        </div>
        <div class="progress" style="height:22px;border-radius:8px;">
            <div id="apnProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                 role="progressbar" style="width:0%;">0%</div>
        </div>
        <div id="apnProgressLabel" class="text-muted small mt-2">Starting…</div>
    </div>
</div>
<script>
(function () {
    var bar   = document.getElementById('apnProgressBar');
    var label = document.getElementById('apnProgressLabel');
    var url   = '<?= APP_URL ?>/app-notifications/process.php?id=<?= (int)$processing_id ?>';
    var fails = 0;
    function tick() {
        fetch(url, { method: 'POST', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (p) {
                if (!p.ok) {
                    label.textContent = p.error || 'Delivery failed.';
                    bar.classList.add('bg-danger');
                    return;
                }
                fails = 0;
                var done = p.sent + p.failed;
                var pct  = p.total > 0 ? Math.round(done * 100 / p.total) : 100;
                bar.style.width = pct + '%';
                bar.textContent = pct + '%';
                label.textContent = done + ' of ' + p.total + ' processed – ' + p.sent + ' sent, ' + p.failed + ' failed.';
                if (p.done) {
                    bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    label.textContent += ' Completed.';
                    setTimeout(function () {
                        window.location = '<?= APP_URL ?>/app-notifications/index.php';
                    }, 1500);
                } else {
                    setTimeout(tick, 300);
                }
            })
            .catch(function () {
                if (++fails > 5) {
                    label.textContent = 'Connection lost – reload this page to resume delivery.';
                    return;
                }
                setTimeout(tick, 3000);
            });
    }
    tick();
})();
</script>
<?php endif; ?>

<?php if (!$configured): ?>
<div class="alert alert-warning d-flex align-items-center gap-3" style="border-radius:10px;">
    <i class="fas fa-exclamation-triangle fa-lg"></i>
    <div>
        <strong>Firebase Cloud Messaging is not configured.</strong>
        Push notifications cannot be delivered until you add a service-account credential.
        <a href="<?= APP_URL ?>/app-notifications/settings.php" class="alert-link">Configure now</a>.
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Compose -->
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-paper-plane me-2 text-primary"></i>Compose Notification</h2>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3 text-muted small">
                    <i class="fas fa-users"></i>
                    <span>
                        <strong><?= (int)$device_count ?></strong> student device<?= $device_count === 1 ? '' : 's' ?>
                        and <strong><?= (int)$user_device_count ?></strong> employee/user device<?= $user_device_count === 1 ? '' : 's' ?> registered.
                        <a href="<?= APP_URL ?>/app-notifications/devices.php">View devices</a>
                    </span>
                </div>

                <?php if ($can_send): ?>
                <form method="post" action="<?= APP_URL ?>/app-notifications/send.php"
                      onsubmit="return confirm('Send this push notification to the selected audience?');">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Send To <span class="text-danger">*</span></label>
                        <select name="audience" id="apnAudience" class="form-select" onchange="apnToggleAudience()">
                            <option value="students">All students</option>
                            <option value="batch">Specific student batch</option>
                            <option value="all_users">All users / employees</option>
                            <option value="all_employees">All employees (administrative + faculty)</option>
                            <option value="user">Individual user</option>
                            <option value="group">Individual user group</option>
                            <option value="employee_type">Employee type</option>
                            <option value="everyone">Everyone (students + users)</option>
                        </select>
                    </div>
                    <div class="mb-3" id="apnBatchWrap" style="display:none;">
                        <label class="form-label">Student Batch <span class="text-danger">*</span></label>
                        <select name="target_batch_id" class="form-select">
                            <option value="">— Choose a batch —</option>
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?><?= !empty($b['dept_name']) ? ' – ' . h($b['dept_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only students of this batch with the app installed receive the push.</div>
                    </div>
                    <div class="mb-3" id="apnUserWrap" style="display:none;">
                        <label class="form-label">User <span class="text-danger">*</span></label>
                        <input type="text" id="apnUserSearch" class="form-control mb-2" autocomplete="off"
                               placeholder="Search by name, email or phone…" oninput="apnFilterUsers()">
                        <select name="target_user_id" id="apnUserSelect" class="form-select">
                            <option value="">— Choose a user —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"
                                    data-search="<?= h(mb_strtolower(trim(($u['full_name'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? '')))) ?>"><?= h($u['full_name']) ?> (<?= h($u['username']) ?>)<?= !empty($u['email']) ? ' – ' . h($u['email']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="apnGroupWrap" style="display:none;">
                        <label class="form-label">User Group <span class="text-danger">*</span></label>
                        <select name="target_group_id" class="form-select">
                            <option value="">— Choose a group —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="apnEtypeWrap" style="display:none;">
                        <label class="form-label">Employee Type <span class="text-danger">*</span></label>
                        <select name="employee_type" class="form-select">
                            <option value="administrative">Administrative</option>
                            <option value="educational">Faculty</option>
                        </select>
                    </div>
                    <script>
                    function apnToggleAudience() {
                        var a = document.getElementById('apnAudience').value;
                        document.getElementById('apnBatchWrap').style.display = (a === 'batch') ? '' : 'none';
                        document.getElementById('apnUserWrap').style.display  = (a === 'user')  ? '' : 'none';
                        document.getElementById('apnGroupWrap').style.display = (a === 'group') ? '' : 'none';
                        document.getElementById('apnEtypeWrap').style.display = (a === 'employee_type') ? '' : 'none';
                    }

                    // Filter the "Individual user" dropdown by name, username, email or phone.
                    var apnUserOptions = null;
                    function apnFilterUsers() {
                        var sel = document.getElementById('apnUserSelect');
                        var q   = document.getElementById('apnUserSearch').value.trim().toLowerCase();
                        if (apnUserOptions === null) {
                            apnUserOptions = Array.prototype.map.call(sel.options, function (o) {
                                return { value: o.value, text: o.text, search: (o.getAttribute('data-search') || '').toLowerCase() };
                            });
                        }
                        var current = sel.value;
                        sel.innerHTML = '';
                        var matches = 0;
                        apnUserOptions.forEach(function (o) {
                            if (o.value === '' || q === '' || o.search.indexOf(q) !== -1) {
                                sel.add(new Option(o.text, o.value));
                                if (o.value !== '') matches++;
                            }
                        });
                        if (q !== '') {
                            sel.options[0].text = matches
                                ? '— ' + matches + ' match' + (matches === 1 ? '' : 'es') + ', choose a user —'
                                : '— No users match your search —';
                        }
                        // Keep the current selection when it still matches the filter.
                        var keep = Array.prototype.some.call(sel.options, function (o) { return o.value === current; });
                        sel.value = keep ? current : '';
                    }
                    </script>
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" maxlength="150" required
                               value="<?= old('title') ?>" placeholder="e.g. Semester Result Published">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="4" required
                                  placeholder="Write the notification message…"><?= old('body') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Link URL <span class="text-muted">(optional)</span></label>
                        <input type="url" name="url" class="form-control"
                               value="<?= old('url') ?>" placeholder="https://…">
                        <div class="form-text">Opened in the app when the student taps the notification.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" <?= $configured ? '' : 'disabled' ?>>
                        <i class="fas fa-paper-plane me-1"></i> Publish &amp; Send Push
                    </button>
                </form>
                <?php else: ?>
                <div class="text-muted small">You do not have permission to send notifications.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-history me-2 text-primary"></i>Sent Notifications</h2>
            </div>
            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-bell-slash fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0">No notifications have been sent yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Delivery</th>
                                <th>Status</th>
                                <th>Sent By</th>
                                <th>Date</th>
                                <?php if ($can_send): ?>
                                <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/app-notifications/view.php?id=<?= (int)$n['id'] ?>"
                                   class="fw-semibold text-decoration-none"><?= h($n['title']) ?></a>
                            </td>
                            <td>
                                <span class="text-success fw-semibold"><?= (int)$n['sent_count'] ?></span>
                                <?php if ((int)$n['failed_count'] > 0): ?>
                                / <span class="text-danger"><?= (int)$n['failed_count'] ?> failed</span>
                                <?php endif; ?>
                                <span class="text-muted small">of <?= (int)$n['total_tokens'] ?></span>
                            </td>
                            <td><?= apn_status_badge($n['status']) ?></td>
                            <td><?= h($n['sender_name'] ?? '—') ?></td>
                            <td><span title="<?= h($n['created_at']) ?>"><?= h(date('d M Y H:i', strtotime($n['created_at']))) ?></span></td>
                            <?php if ($can_send): ?>
                            <td class="text-end text-nowrap">
                                <?php if (in_array($n['status'], ['queued', 'sending'], true)): ?>
                                <a href="<?= APP_URL ?>/app-notifications/index.php?processing=<?= (int)$n['id'] ?>"
                                   class="btn btn-sm btn-outline-success" title="Resume delivery">
                                    <i class="fas fa-play"></i>
                                </a>
                                <?php endif; ?>
                                <form method="post" action="<?= APP_URL ?>/app-notifications/resend.php" class="d-inline"
                                      onsubmit="return confirm('Resend this notification to its original audience?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                            title="Resend to the same audience" <?= $configured ? '' : 'disabled' ?>>
                                        <i class="fas fa-redo"></i>
                                    </button>
                                </form>
                                <a href="<?= APP_URL ?>/app-notifications/index.php?reuse=<?= (int)$n['id'] ?>#apnAudience"
                                   class="btn btn-sm btn-outline-secondary" title="Reuse in the compose form">
                                    <i class="fas fa-copy"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
