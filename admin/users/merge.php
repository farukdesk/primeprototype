<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_super_admin();

$page_title = 'Merge Users';
$me         = auth_user();

// ── Helpers ──────────────────────────────────────────────────────────────────

function merge_find_user(int $id): ?array
{
    if ($id <= 0) return null;
    $st = db()->prepare(
        'SELECT u.*, g.name AS group_name, g.is_super
           FROM users u
           LEFT JOIN user_groups g ON g.id = u.group_id
          WHERE u.id = ?'
    );
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

function merge_search_users(string $q, array $exclude_ids = []): array
{
    $like   = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
    $not_in = '';
    $exclude_ids = array_values(array_filter(array_map('intval', $exclude_ids)));
    if (!empty($exclude_ids)) {
        $not_in = ' AND u.id NOT IN (' . implode(',', array_fill(0, count($exclude_ids), '?')) . ')';
        $params = array_merge($params, $exclude_ids);
    }
    $st = db()->prepare(
        'SELECT u.id, u.full_name, u.username, u.email, u.phone, u.is_active, u.created_at,
                g.name AS group_name
           FROM users u
           LEFT JOIN user_groups g ON g.id = u.group_id
          WHERE (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)' . $not_in . '
          ORDER BY u.full_name
          LIMIT 20'
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Discover every table + column in this database that references users.id.
 *
 * Combines the declared foreign keys with a name-based heuristic so that
 * tables created without FK constraints (attendance, leave, tickets, logs,
 * group assignments, …) are covered too. Only integer columns are matched so
 * text columns that store names (not IDs) are never touched. New modules and
 * tables added in the future are picked up automatically.
 *
 * @return array<int, array{table:string, column:string}>
 */
function merge_reference_columns(): array
{
    $db   = db();
    $refs = [];

    // 1) Declared foreign keys pointing at users.id
    $fk = $db->query(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE k
          WHERE k.TABLE_SCHEMA = DATABASE()
            AND k.REFERENCED_TABLE_SCHEMA = DATABASE()
            AND k.REFERENCED_TABLE_NAME = 'users'
            AND k.REFERENCED_COLUMN_NAME = 'id'"
    )->fetchAll();
    foreach ($fk as $r) {
        $refs[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = [
            'table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'],
        ];
    }

    // 2) Heuristic: integer columns whose name looks like a user reference
    $cols = $db->query(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME
           FROM information_schema.COLUMNS c
           JOIN information_schema.TABLES t
             ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
            AND t.TABLE_NAME   = c.TABLE_NAME
            AND t.TABLE_TYPE   = 'BASE TABLE'
          WHERE c.TABLE_SCHEMA = DATABASE()
            AND c.TABLE_NAME <> 'users'
            AND c.DATA_TYPE IN ('int', 'bigint', 'mediumint', 'smallint')
            AND (
                    c.COLUMN_NAME = 'user_id'
                 OR c.COLUMN_NAME LIKE '%\\_user\\_id'
                 OR c.COLUMN_NAME LIKE '%\\_by'
                 OR c.COLUMN_NAME IN ('author_id', 'owner_id', 'assigned_to', 'recipient_id')
            )"
    )->fetchAll();
    foreach ($cols as $r) {
        $refs[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = [
            'table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'],
        ];
    }

    ksort($refs);
    return array_values($refs);
}

/** Count rows in a referencing table that belong to the given user. */
function merge_count_rows(string $table, string $column, int $user_id): int
{
    $t = str_replace('`', '', $table);
    $c = str_replace('`', '', $column);
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
        $st->execute([$user_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$merge_fields = ['full_name' => 'Full Name', 'username' => 'Username', 'email' => 'Email', 'phone' => 'Phone', 'student_sid' => 'Student ID'];

// ── Execute merge (POST) ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $from_id = (int)($_POST['from_id'] ?? 0);   // duplicate account (goes away)
    $to_id   = (int)($_POST['to_id']   ?? 0);   // surviving account (keeps everything)

    $from = merge_find_user($from_id);
    $to   = merge_find_user($to_id);

    if (!$from || !$to) {
        flash_set('error', 'One of the selected users no longer exists.');
        redirect(APP_URL . '/users/merge.php');
    }
    if ($from_id === $to_id) {
        flash_set('error', 'You cannot merge a user into itself.');
        redirect(APP_URL . '/users/merge.php');
    }
    if ($from_id === (int)$me['id']) {
        flash_set('error', 'You cannot merge away your own account.');
        redirect(APP_URL . '/users/merge.php');
    }

    // Which side wins for each identity field (default: surviving account)
    $keep = [];
    foreach ($merge_fields as $f => $label) {
        $keep[$f] = (($_POST['keep_' . $f] ?? 'target') === 'source') ? 'source' : 'target';
    }
    $disposal = (($_POST['disposal'] ?? 'delete') === 'deactivate') ? 'deactivate' : 'delete';

    $refs    = merge_reference_columns();
    $db      = db();
    $report  = [];
    $skipped = [];
    $moved_total = 0;

    try {
        $db->beginTransaction();

        // 1) Free the duplicate's unique username / email so they can be given
        //    to the surviving account without unique-key conflicts.
        $db->prepare(
            "UPDATE users
                SET username = SUBSTRING(CONCAT('merged', id, '_', username), 1, 60),
                    email    = SUBSTRING(CONCAT('merged', id, '_', email), 1, 190)
              WHERE id = ?"
        )->execute([$from_id]);

        // 2) Apply the chosen identity fields to the surviving account.
        //    ($from was loaded before the rename above, so its original values are used.)
        $final = [];
        foreach (['full_name', 'username', 'email'] as $f) {
            $final[$f] = $keep[$f] === 'source' ? (string)$from[$f] : (string)$to[$f];
        }
        foreach (['phone', 'student_sid'] as $f) {
            $primary   = $keep[$f] === 'source' ? ($from[$f] ?? '') : ($to[$f] ?? '');
            $fallback  = $keep[$f] === 'source' ? ($to[$f] ?? '')   : ($from[$f] ?? '');
            $final[$f] = ($primary !== '' && $primary !== null) ? $primary : $fallback;
        }
        $db->prepare(
            'UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, student_sid = ? WHERE id = ?'
        )->execute([$final['full_name'], $final['username'], $final['email'], $final['phone'], $final['student_sid'], $to_id]);

        // 3) Re-point every referencing row (attendance, access, leave, tickets,
        //    logs, group assignments, …) from the duplicate to the survivor.
        foreach ($refs as $ref) {
            $t = str_replace('`', '', $ref['table']);
            $c = str_replace('`', '', $ref['column']);
            try {
                $cnt = $db->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
                $cnt->execute([$from_id]);
                $n = (int)$cnt->fetchColumn();
                if ($n === 0) continue;

                // UPDATE IGNORE skips rows that would break a unique key
                // (e.g. both accounts assigned to the same user group)…
                $db->prepare("UPDATE IGNORE `$t` SET `$c` = ? WHERE `$c` = ?")->execute([$to_id, $from_id]);

                // …anything still pointing at the duplicate is a row the
                // surviving account already has — safe to drop.
                $left = $db->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
                $left->execute([$from_id]);
                $dupes = (int)$left->fetchColumn();
                if ($dupes > 0) {
                    $db->prepare("DELETE FROM `$t` WHERE `$c` = ?")->execute([$from_id]);
                }

                $moved_total += ($n - $dupes);
                $report[] = "$t.$c: moved " . ($n - $dupes) . ($dupes > 0 ? ", removed $dupes duplicate row(s)" : '');
            } catch (Throwable $e) {
                $skipped[] = "$t.$c (" . $e->getMessage() . ')';
            }
        }

        // 4) Group assignments sanity: keep exactly one primary group that
        //    matches the survivor's users.group_id.
        try {
            $db->prepare(
                'UPDATE user_group_assignments SET is_primary = 0
                  WHERE user_id = ? AND group_id <> (SELECT group_id FROM users WHERE id = ?)'
            )->execute([$to_id, $to_id]);
            $db->prepare(
                'UPDATE user_group_assignments SET is_primary = 1
                  WHERE user_id = ? AND group_id = (SELECT group_id FROM users WHERE id = ?)'
            )->execute([$to_id, $to_id]);
        } catch (Throwable $e) {
            // user_group_assignments not present — ignore.
        }

        // 5) Dispose of the duplicate account.
        $disposed = '';
        if ($disposal === 'delete') {
            try {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$from_id]);
                $disposed = 'deleted';
            } catch (Throwable $e) {
                // A remaining FK blocks deletion — deactivate instead so the merge still completes.
                $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$from_id]);
                $disposed = 'deactivated (delete blocked by a database constraint)';
            }
        } else {
            $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$from_id]);
            $disposed = 'deactivated';
        }

        $db->commit();

        $summary = 'Merged user #' . $from_id . ' (' . $from['username'] . ' / ' . $from['email'] . ')'
                 . ' into #' . $to_id . ' (' . $final['username'] . ' / ' . $final['email'] . '). '
                 . 'Moved ' . $moved_total . ' related row(s) across ' . count($report) . ' table column(s). '
                 . 'Duplicate account ' . $disposed . '.'
                 . ($skipped ? ' Skipped: ' . implode('; ', $skipped) : '');

        log_change(
            'users',
            'UPDATE',
            $to_id,
            $final['username'] . ' (' . $final['email'] . ')',
            'merge',
            'from user #' . $from_id . ' (' . $from['username'] . ')',
            'into user #' . $to_id,
            $summary . ($report ? ' Details: ' . implode(' | ', $report) : '')
        );

        flash_set('success',
            'Merged <strong>' . h($from['full_name']) . '</strong> into <strong>' . h($to['full_name']) . '</strong>. '
            . 'Moved <strong>' . $moved_total . '</strong> related record(s). Duplicate account ' . h($disposed) . '.'
            . ($skipped ? '<br><small class="text-muted">Some tables were skipped: ' . h(implode('; ', $skipped)) . '</small>' : '')
        );
        redirect(APP_URL . '/users/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash_set('error', 'Merge failed — nothing was changed. ' . h($e->getMessage()));
        redirect(APP_URL . '/users/merge.php?from_id=' . $from_id . '&to_id=' . $to_id);
    }
}

// ── Selection state (GET) ────────────────────────────────────────────────────

$from_id = (int)($_GET['from_id'] ?? 0);
$to_id   = (int)($_GET['to_id']   ?? 0);
$q_from  = trim($_GET['q_from'] ?? '');
$q_to    = trim($_GET['q_to']   ?? '');

$from = merge_find_user($from_id);
$to   = merge_find_user($to_id);

$selection_error = '';
if ($from && $to && $from_id === $to_id) {
    $selection_error = 'The duplicate and the surviving account must be two different users.';
    $to = null; $to_id = 0;
}
if ($from && $from_id === (int)$me['id']) {
    $selection_error = 'You cannot merge away your own account. Pick another duplicate.';
    $from = null; $from_id = 0;
}

$results_from = ($q_from !== '') ? merge_search_users($q_from, [$to_id, (int)$me['id']]) : [];
$results_to   = ($q_to   !== '') ? merge_search_users($q_to, [$from_id]) : [];

// Related-data preview for the confirmation step
$preview_counts = [];
if ($from && $to) {
    foreach (merge_reference_columns() as $ref) {
        $n_from = merge_count_rows($ref['table'], $ref['column'], $from_id);
        if ($n_from > 0) {
            $preview_counts[] = [
                'table'  => $ref['table'],
                'column' => $ref['column'],
                'from'   => $n_from,
                'to'     => merge_count_rows($ref['table'], $ref['column'], $to_id),
            ];
        }
    }
}

require_once __DIR__ . '/../includes/header.php';

/** Small user card used in both pick lists and the confirmation step. */
function merge_user_card(array $u, string $accent): string
{
    $html  = '<div class="d-flex align-items-center gap-2">';
    $html .= '<div style="width:38px;height:38px;border-radius:50%;background:' . $accent . ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0;">'
           . strtoupper(substr((string)$u['full_name'], 0, 1)) . '</div>';
    $html .= '<div><div class="fw-semibold">' . h($u['full_name'])
           . (empty($u['is_active']) ? ' <span class="badge bg-secondary" style="font-size:.65rem;">Inactive</span>' : '')
           . '</div>';
    $html .= '<div class="small text-muted">@' . h($u['username']) . ' · ' . h($u['email'])
           . (!empty($u['group_name']) ? ' · ' . h($u['group_name']) : '') . '</div></div></div>';
    return $html;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Merge Users</li>
        </ol>
    </nav>
</div>

<?php if ($selection_error): ?>
<div class="alert alert-warning" style="border-radius:12px;"><?= h($selection_error) ?></div>
<?php endif; ?>

<?php if (!$from || !$to): ?>

<!-- ── Step 1: pick the two accounts ─────────────────────────────────────── -->
<div class="alert alert-info" style="border-radius:12px;">
    <i class="fas fa-info-circle me-1"></i>
    Pick the <strong>duplicate account</strong> (its data will be moved away and the account removed)
    and the <strong>surviving account</strong> (it receives all attendance, access, records and — if you choose — the email/username).
</div>

<div class="row g-4">
    <?php
    $panels = [
        ['key' => 'from', 'title' => 'Duplicate account (merge FROM)', 'accent' => '#dc3545', 'icon' => 'fa-user-minus',
         'picked' => $from, 'q' => $q_from, 'results' => $results_from, 'other_key' => 'to', 'other_id' => $to_id],
        ['key' => 'to', 'title' => 'Surviving account (merge INTO)', 'accent' => '#198754', 'icon' => 'fa-user-check',
         'picked' => $to, 'q' => $q_to, 'results' => $results_to, 'other_key' => 'from', 'other_id' => $from_id],
    ];
    foreach ($panels as $p): ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <strong><i class="fas <?= $p['icon'] ?> me-2" style="color:<?= $p['accent'] ?>;"></i><?= h($p['title']) ?></strong>
            </div>
            <div class="card-body p-4">
                <?php if ($p['picked']): ?>
                    <div class="border rounded p-3 mb-3" style="border-radius:12px;">
                        <?= merge_user_card($p['picked'], $p['accent']) ?>
                    </div>
                    <a class="btn btn-sm btn-light" style="border-radius:8px;"
                       href="<?= h(APP_URL . '/users/merge.php?' . http_build_query([$p['other_key'] . '_id' => $p['other_id']])) ?>">
                        <i class="fas fa-times me-1"></i>Change selection
                    </a>
                <?php else: ?>
                    <form method="GET" class="d-flex gap-2 mb-3">
                        <?php if ($p['other_id']): ?>
                        <input type="hidden" name="<?= $p['other_key'] ?>_id" value="<?= (int)$p['other_id'] ?>">
                        <?php endif; ?>
                        <input type="text" name="q_<?= $p['key'] ?>" class="form-control" style="border-radius:10px;"
                               placeholder="Search name, username, email, phone…" value="<?= h($p['q']) ?>" autofocus>
                        <button class="btn btn-outline-primary" style="border-radius:10px;"><i class="fas fa-search"></i></button>
                    </form>
                    <?php if ($p['q'] !== '' && empty($p['results'])): ?>
                        <p class="text-muted small mb-0">No users match “<?= h($p['q']) ?>”.</p>
                    <?php endif; ?>
                    <?php foreach ($p['results'] as $r): ?>
                    <a class="d-block text-decoration-none text-body border rounded p-2 mb-2"
                       style="border-radius:10px;"
                       href="<?= h(APP_URL . '/users/merge.php?' . http_build_query(array_filter([
                           $p['key'] . '_id'       => (int)$r['id'],
                           $p['other_key'] . '_id' => $p['other_id'] ?: null,
                           'q_' . $p['other_key']  => $p['other_key'] === 'from' ? ($q_from ?: null) : ($q_to ?: null),
                       ]))) ?>">
                        <?= merge_user_card($r, $p['accent']) ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>

<!-- ── Step 2: review & confirm ──────────────────────────────────────────── -->
<form method="POST" action="<?= APP_URL ?>/users/merge.php"
      onsubmit="return confirm('Merge <?= h(addslashes($from['full_name'])) ?> into <?= h(addslashes($to['full_name'])) ?>? This moves ALL related data and cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="from_id" value="<?= (int)$from_id ?>">
    <input type="hidden" name="to_id"   value="<?= (int)$to_id ?>">

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100 border-danger">
                <div class="card-header py-3 px-4 bg-danger bg-opacity-10">
                    <strong class="text-danger"><i class="fas fa-user-minus me-2"></i>Duplicate (will be removed)</strong>
                </div>
                <div class="card-body p-4"><?= merge_user_card($from, '#dc3545') ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-success">
                <div class="card-header py-3 px-4 bg-success bg-opacity-10">
                    <strong class="text-success"><i class="fas fa-user-check me-2"></i>Surviving account (keeps everything)</strong>
                </div>
                <div class="card-body p-4"><?= merge_user_card($to, '#198754') ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($from['is_super'])): ?>
    <div class="alert alert-warning" style="border-radius:12px;">
        <i class="fas fa-exclamation-triangle me-1"></i>
        The duplicate account belongs to a <strong>Super Admin</strong> group. Its group access will be transferred to the surviving account — double-check this is intended.
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header py-3 px-4"><strong><i class="fas fa-id-card me-2 text-muted"></i>Which details should the surviving account keep?</strong></div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Field</th><th>Keep survivor's value</th><th>Take duplicate's value</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($merge_fields as $f => $label):
                        $tv = (string)($to[$f]   ?? '');
                        $fv = (string)($from[$f] ?? '');
                        // Default: keep the survivor's value; if the survivor's is empty and the duplicate has one, take the duplicate's.
                        $default_source = ($tv === '' && $fv !== '');
                    ?>
                        <tr>
                            <td class="fw-semibold" style="width:160px;"><?= h($label) ?></td>
                            <td>
                                <label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">
                                    <input type="radio" class="form-check-input mt-0" name="keep_<?= $f ?>" value="target" <?= $default_source ? '' : 'checked' ?>>
                                    <span><?= $tv !== '' ? h($tv) : '<span class="text-muted">— empty —</span>' ?></span>
                                </label>
                            </td>
                            <td>
                                <label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">
                                    <input type="radio" class="form-check-input mt-0" name="keep_<?= $f ?>" value="source" <?= $default_source ? 'checked' : '' ?>>
                                    <span><?= $fv !== '' ? h($fv) : '<span class="text-muted">— empty —</span>' ?></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-3 mb-0">
                For Phone and Student ID, if the chosen side is empty the other side's value is used automatically. The surviving account's password is kept.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <strong><i class="fas fa-database me-2 text-muted"></i>Data that will move to the surviving account</strong>
        </div>
        <div class="card-body p-0">
            <?php if (empty($preview_counts)): ?>
                <p class="text-muted p-4 mb-0">The duplicate account has no related records — only the account details will be merged.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4">Table</th>
                            <th>Reference</th>
                            <th class="text-end">Duplicate's rows</th>
                            <th class="text-end pe-4">Survivor's rows</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview_counts as $pc): ?>
                        <tr>
                            <td class="px-4"><code><?= h($pc['table']) ?></code></td>
                            <td><code><?= h($pc['column']) ?></code></td>
                            <td class="text-end"><span class="badge bg-danger bg-opacity-10 text-danger"><?= (int)$pc['from'] ?></span></td>
                            <td class="text-end pe-4"><span class="badge bg-success bg-opacity-10 text-success"><?= (int)$pc['to'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small px-4 py-3 mb-0">
                Attendance, group access, leave, tickets, logs and every other linked record shown above will be re-assigned to the surviving account.
                Where both accounts hold the same record (e.g. the same group membership), the duplicate's copy is removed automatically.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header py-3 px-4"><strong><i class="fas fa-user-slash me-2 text-muted"></i>After the merge, the duplicate account should be…</strong></div>
        <div class="card-body p-4">
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="disposal" id="dispDelete" value="delete" checked>
                <label class="form-check-label" for="dispDelete"><strong>Deleted</strong> — removed permanently (recommended).</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="disposal" id="dispDeactivate" value="deactivate">
                <label class="form-check-label" for="dispDeactivate"><strong>Deactivated</strong> — kept (renamed, cannot log in) in case you want to review it first.</label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-danger" style="border-radius:10px;">
            <i class="fas fa-people-arrows me-1"></i> Merge Users
        </button>
        <a href="<?= APP_URL ?>/users/merge.php" class="btn btn-light" style="border-radius:10px;">Start Over</a>
        <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
