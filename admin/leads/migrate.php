<?php
/**
 * Legacy CRM Migration – web UI
 * Lets a super-admin run the admin_67crm → primeprototype migration
 * entirely from the browser, without needing command-line access.
 *
 * POST actions
 *   schema  – run call-logs.sql + leads-gpa.sql (schema only, no data)
 *   migrate – upload admin_67crm.sql, import as crm_import_ staging tables,
 *             then run migrate-from-67crm.sql
 *   cleanup – DROP TABLE all crm_import_* staging tables
 */

require_once __DIR__ . '/../includes/auth.php';
require_super_admin();   // Super-admins only

set_time_limit(600);     // long-running import
ini_set('memory_limit', '512M');

$page_title = 'Legacy CRM Migration';
$user       = auth_user();

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Split a SQL dump string into individual executable statements.
 * Handles -- line comments, slash-star block comments, quoted strings,
 * and MySQL conditional comments (treated as executable).
 */
function sql_split(string $sql): array {
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $i          = 0;
    $in_single  = false;   // inside '...'
    $in_double  = false;   // inside "..."
    $in_backtick= false;   // inside `...`

    while ($i < $len) {
        $ch   = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // ── Escape sequences – must be checked BEFORE quote toggles ─────────
        if ($ch === '\\' && ($in_single || $in_double)) {
            $current .= $ch . $next; $i += 2; continue;
        }

        // ── String tracking ──────────────────────────────────────────────────
        if ($ch === "'" && !$in_double && !$in_backtick) {
            $in_single = !$in_single;
            $current .= $ch; $i++; continue;
        }
        if ($ch === '"' && !$in_single && !$in_backtick) {
            $in_double = !$in_double;
            $current .= $ch; $i++; continue;
        }
        if ($ch === '`' && !$in_single && !$in_double) {
            $in_backtick = !$in_backtick;
            $current .= $ch; $i++; continue;
        }

        if ($in_single || $in_double || $in_backtick) {
            $current .= $ch; $i++; continue;
        }

        // ── Comments ─────────────────────────────────────────────────────────
        // -- line comment
        if ($ch === '-' && $next === '-') {
            $eol = strpos($sql, "\n", $i);
            $i   = ($eol === false) ? $len : $eol + 1;
            continue;
        }
        // # line comment
        if ($ch === '#') {
            $eol = strpos($sql, "\n", $i);
            $i   = ($eol === false) ? $len : $eol + 1;
            continue;
        }
        // /* block comment */ — MySQL conditional /*!…*/ is kept as executable
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { $i = $len; continue; }
            $block = substr($sql, $i, $end + 2 - $i);
            // /*!NNNNNN ... */ — treat as regular SQL
            if (isset($sql[$i + 2]) && $sql[$i + 2] === '!') {
                // strip the /*! and */ markers, keep inner SQL
                $inner = preg_replace('/^\/\*!\d*\s*/', '', rtrim(substr($block, 0, -2)));
                $current .= ' ' . $inner . ' ';
            }
            $i = $end + 2;
            continue;
        }

        // ── Statement separator ───────────────────────────────────────────────
        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    // Final statement without trailing semicolon
    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

/**
 * Execute all statements in a SQL string against the given PDO connection.
 * Returns ['ok' => bool, 'executed' => int, 'error' => string|null]
 */
function sql_exec_all(PDO $pdo, string $sql): array {
    $statements = sql_split($sql);
    $executed   = 0;

    foreach ($statements as $stmt) {
        // Skip pure SET NAMES / SET CHARACTER SET (PDO handles charset itself)
        if (preg_match('/^SET\s+(NAMES|CHARACTER\s+SET)\b/i', $stmt)) {
            $executed++;
            continue;
        }
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (PDOException $e) {
            // Ignore "Column already exists" (MySQL 1060) – idempotent ALTERs
            if ((int)$e->getCode() === 1060 || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1060)) {
                $executed++; continue;
            }
            // Limit statement snippet to avoid exposing sensitive data
            $snippet = htmlspecialchars(substr($stmt, 0, 200), ENT_QUOTES, 'UTF-8');
            return ['ok' => false, 'executed' => $executed, 'error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '<br><small class="text-muted">Near: <code>' . $snippet . '…</code></small>'];
        }
    }

    return ['ok' => true, 'executed' => $executed, 'error' => null];
}

/**
 * Prepare a legacy CRM SQL dump for import into the main application database.
 *
 * - Strips DELIMITER $$ … DELIMITER ; blocks (stored functions / procedures)
 *   that require SUPER privilege.  Only the $$ delimiter used by mysqldump
 *   is handled; other custom delimiters are not supported.
 * - Strips CREATE DATABASE and USE statements.
 * - Converts CREATE TABLE to CREATE TABLE IF NOT EXISTS so re-runs are safe.
 * - Prefixes every backtick-quoted table name with "crm_import_" so staging data
 *   sits alongside the app tables without requiring a separate database.
 */
function prepare_staging_sql(string $sql): string {
    // 1. Remove DELIMITER $$ ... DELIMITER ; blocks (functions, procedures, triggers).
    //    mysqldump always uses $$ as the alternate delimiter.
    $sql = preg_replace('/DELIMITER\s+\$\$.*?DELIMITER\s+;/si', '', $sql);

    // 2. Remove CREATE DATABASE and USE statements
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\b[^;]*;\s*/im', '', $sql);
    $sql = preg_replace('/^\s*USE\s+`?[^`;\s]+`?\s*;\s*/im', '', $sql);

    // 3. Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS (idempotent re-runs).
    //    The word boundary after EXISTS handles any whitespace that follows.
    $sql = preg_replace('/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i', 'CREATE TABLE IF NOT EXISTS ', $sql);

    // 4. Prefix every backtick-quoted table name that follows a table-manipulating keyword.
    //    Covers: CREATE TABLE [IF NOT EXISTS], INSERT [IGNORE] INTO,
    //            DROP TABLE [IF EXISTS], TRUNCATE [TABLE], ALTER TABLE, REFERENCES.
    $prefix = 'crm_import_';
    $sql = preg_replace_callback(
        '/\b(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
        . '|INSERT\s+(?:IGNORE\s+)?INTO'
        . '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
        . '|TRUNCATE\s+(?:TABLE\s+)?'
        . '|ALTER\s+TABLE'
        . '|REFERENCES'
        . ')\s+`([^`]+)`/i',
        static function (array $m) use ($prefix): string {
            $keyword = $m[1];
            $tbl     = $m[2];
            // Convert plain INSERT INTO → INSERT IGNORE INTO so that re-running
            // the migration does not fail with "Duplicate entry" errors when the
            // staging tables already contain data from a previous import.
            if (preg_match('/^INSERT\s+INTO$/i', trim($keyword))) {
                $keyword = 'INSERT IGNORE INTO';
            }
            if (str_starts_with($tbl, $prefix)) {
                return $keyword . ' `' . $tbl . '`';
            }
            return $keyword . ' `' . $prefix . $tbl . '`';
        },
        $sql
    );

    // 5. Prefix backtick-quoted constraint names in ADD CONSTRAINT clauses.
    //    In MySQL, FK constraint names must be unique within the whole database,
    //    not just the table.  Without this step importing crm_import_users would
    //    fail with errno 121 (duplicate key name) because the original names
    //    (e.g. users_ibfk_1, users_company_fk) are already in use by the live
    //    tables that share the same database.
    $sql = preg_replace_callback(
        '/\bADD\s+CONSTRAINT\s+`([^`]+)`/i',
        static function (array $m) use ($prefix): string {
            $name = $m[1];
            if (str_starts_with($name, $prefix)) {
                return 'ADD CONSTRAINT `' . $name . '`';
            }
            return 'ADD CONSTRAINT `' . $prefix . $name . '`';
        },
        $sql
    );

    return $sql;
}

// ── Status checks ─────────────────────────────────────────────────────────────

$schema_done = false;
$crm_import_exists = false;

try {
    // Check GPA columns
    $cols = db()->query("SHOW COLUMNS FROM `leads` LIKE 'ssc_gpa'")->fetchAll();
    $call_tbl = db()->query("SHOW TABLES LIKE 'lead_call_logs'")->fetchAll();
    $schema_done = !empty($cols) && !empty($call_tbl);
} catch (Exception $e) {}

try {
    $staging_tbls = db()->query("SHOW TABLES LIKE 'crm_import_%'")->fetchAll();
    $crm_import_exists = !empty($staging_tbls);
} catch (Exception $e) {}

// ── POST handling ─────────────────────────────────────────────────────────────

$results = [];   // array of ['step' => string, 'ok' => bool, 'msg' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    // ── ACTION: schema ────────────────────────────────────────────────────────
    if ($action === 'schema') {
        $sql_files = [
            'call-logs.sql'  => __DIR__ . '/call-logs.sql',
            'leads-gpa.sql'  => __DIR__ . '/leads-gpa.sql',
        ];

        foreach ($sql_files as $label => $path) {
            if (!file_exists($path)) {
                $results[] = ['step' => $label, 'ok' => false, 'msg' => 'File not found on server.'];
                continue;
            }
            $sql = file_get_contents($path);
            $res = sql_exec_all(db(), $sql);
            $results[] = [
                'step' => $label,
                'ok'   => $res['ok'],
                'msg'  => $res['ok']
                    ? "✔ Executed {$res['executed']} statement(s) successfully."
                    : "✘ Error after {$res['executed']} statement(s): " . $res['error'],
            ];
            if (!$res['ok']) break;
        }

        // Refresh status
        try {
            $cols = db()->query("SHOW COLUMNS FROM `leads` LIKE 'ssc_gpa'")->fetchAll();
            $call_tbl = db()->query("SHOW TABLES LIKE 'lead_call_logs'")->fetchAll();
            $schema_done = !empty($cols) && !empty($call_tbl);
        } catch (Exception $e) {}
    }

    // ── ACTION: migrate ───────────────────────────────────────────────────────
    if ($action === 'migrate') {
        $upload = $_FILES['sql_file'] ?? null;

        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
            $upload_err_map = [
                UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server\'s upload_max_filesize limit (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form\'s MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary upload directory.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write the file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];
            $err_code = $upload['error'] ?? -1;
            $err_msg  = $upload_err_map[$err_code] ?? 'Unknown upload error (code ' . $err_code . ').';
            $results[] = ['step' => 'Upload', 'ok' => false, 'msg' => $err_msg];
        } else {
            $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $results[] = ['step' => 'Upload', 'ok' => false, 'msg' => 'Only .sql files are accepted.'];
            } else {
                $results[] = ['step' => 'Upload', 'ok' => true, 'msg' => '✔ File "' . h($upload['name']) . '" received (' . number_format($upload['size'] / 1024, 1) . ' KB).'];

                // ── Step A: Import uploaded dump into staging tables (crm_import_ prefix) ──
                $step_ok = true;
                $sql_content = file_get_contents($upload['tmp_name']);
                if ($sql_content === false) {
                    $results[] = ['step' => 'Import staging tables', 'ok' => false, 'msg' => '✘ Could not read uploaded file.'];
                    $step_ok = false;
                } else {
                    try {
                        $staging_sql = prepare_staging_sql($sql_content);
                        $res = sql_exec_all(db(), $staging_sql);
                        if ($res['ok']) {
                            $results[] = ['step' => 'Import staging tables', 'ok' => true, 'msg' => "✔ Executed {$res['executed']} statement(s) — legacy data loaded into staging tables."];
                            $crm_import_exists = true;
                        } else {
                            $results[] = ['step' => 'Import staging tables', 'ok' => false, 'msg' => '✘ ' . $res['error']];
                            $step_ok = false;
                        }
                    } catch (PDOException $e) {
                        $results[] = ['step' => 'Import staging tables', 'ok' => false, 'msg' => '✘ ' . h($e->getMessage())];
                        $step_ok = false;
                    }
                }

                // ── Step B: Run schema files if not yet done ──────────────────
                if ($step_ok && !$schema_done) {
                    foreach (['call-logs.sql', 'leads-gpa.sql'] as $fname) {
                        $path = __DIR__ . '/' . $fname;
                        if (!file_exists($path)) continue;
                        $res = sql_exec_all(db(), file_get_contents($path));
                        $results[] = [
                            'step' => $fname,
                            'ok'   => $res['ok'],
                            'msg'  => $res['ok']
                                ? "✔ Schema applied ({$res['executed']} statements)."
                                : '✘ ' . $res['error'],
                        ];
                        if (!$res['ok']) { $step_ok = false; break; }
                    }
                    // Refresh flag
                    try {
                        $cols = db()->query("SHOW COLUMNS FROM `leads` LIKE 'ssc_gpa'")->fetchAll();
                        $call_tbl = db()->query("SHOW TABLES LIKE 'lead_call_logs'")->fetchAll();
                        $schema_done = !empty($cols) && !empty($call_tbl);
                    } catch (Exception $e) {}
                }

                // ── Step C: Run migrate-from-67crm.sql ────────────────────────
                if ($step_ok) {
                    $migrate_path = __DIR__ . '/migrate-from-67crm.sql';
                    if (!file_exists($migrate_path)) {
                        $results[] = ['step' => 'migrate-from-67crm.sql', 'ok' => false, 'msg' => 'Migration script not found on server.'];
                        $step_ok = false;
                    } else {
                        $res = sql_exec_all(db(), file_get_contents($migrate_path));
                        $results[] = [
                            'step' => 'migrate-from-67crm.sql',
                            'ok'   => $res['ok'],
                            'msg'  => $res['ok']
                                ? "✔ Migration complete ({$res['executed']} statements executed)."
                                : '✘ ' . $res['error'],
                        ];
                        $step_ok = $res['ok'];
                    }
                }

                // ── Step D: Record counts ─────────────────────────────────────
                if ($step_ok) {
                    $count_tables = [
                        'leads'             => 'Leads',
                        'lead_notes'        => 'Lead Notes',
                        'lead_history'      => 'Lead History',
                        'lead_assignments'  => 'Lead Assignments',
                        'lead_call_logs'    => 'Call Logs',
                        'lead_appointments' => 'Campus Appointments',
                    ];
                    $counts = [];
                    foreach ($count_tables as $tbl => $label) {
                        try {
                            $n = (int)db()->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                            $counts[] = "{$label}: <strong>" . number_format($n) . '</strong>';
                        } catch (Exception $e) {
                            $counts[] = "{$label}: <em>n/a</em>";
                        }
                    }
                    $results[] = [
                        'step' => 'Record counts (current totals)',
                        'ok'   => true,
                        'msg'  => implode(' &nbsp;·&nbsp; ', $counts),
                    ];
                }
            }
        }
    }


    // ── ACTION: cleanup ───────────────────────────────────────────────────────
    if ($action === 'cleanup') {
        try {
            $tables = db()->query("SHOW TABLES LIKE 'crm_import_%'")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (empty($tables)) {
                $results[] = ['step' => 'Cleanup', 'ok' => true, 'msg' => '✔ No staging tables found — nothing to drop.'];
            } else {
                db()->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $tbl) {
                    // Validate name matches expected pattern before use in SQL
                    if (!preg_match('/^crm_import_[a-zA-Z0-9_]+$/', $tbl)) {
                        continue;
                    }
                    db()->exec('DROP TABLE IF EXISTS `' . $tbl . '`');
                }
                db()->exec('SET FOREIGN_KEY_CHECKS=1');
                $results[] = ['step' => 'Cleanup', 'ok' => true, 'msg' => '✔ Dropped ' . count($tables) . ' staging table(s).'];
                $crm_import_exists = false;
            }
        } catch (PDOException $e) {
            $results[] = ['step' => 'Cleanup', 'ok' => false, 'msg' => '✘ ' . h($e->getMessage())];
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-database me-2 text-warning"></i>Legacy CRM Migration</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item active">67CRM Migration</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Leads
    </a>
</div>

<?= flash_show() ?>

<!-- ── Results ── -->
<?php if ($results): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold py-2"><i class="fas fa-terminal me-2"></i>Migration Log</div>
    <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr><th style="width:220px">Step</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr class="<?= $r['ok'] ? 'table-success' : 'table-danger' ?>">
                    <td class="fw-semibold"><?= h($r['step']) ?></td>
                    <td><?= $r['msg'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<!-- ── Status badges ── -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <?php if ($schema_done): ?>
                    <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-check me-1"></i>Schema Ready</span>
                    <span class="text-muted small">GPA columns &amp; call_logs table exist.</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i>Schema Needed</span>
                    <span class="text-muted small">GPA columns or call_logs table are missing.</span>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <?php if ($crm_import_exists): ?>
                    <span class="badge bg-info fs-6 px-3 py-2"><i class="fas fa-database me-1"></i>Staging Tables Exist</span>
                    <span class="text-muted small">Legacy data is loaded. You may clean it up after migration.</span>
                <?php else: ?>
                    <span class="badge bg-secondary fs-6 px-3 py-2"><i class="fas fa-database me-1"></i>No Staging Tables</span>
                    <span class="text-muted small">Staging tables will be created during migration.</span>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <span class="badge bg-danger fs-6 px-3 py-2"><i class="fas fa-shield-alt me-1"></i>Super Admin Only</span>
                <span class="text-muted small">This page is restricted. All actions are logged.</span>
            </div>
        </div>
    </div>
</div>

<!-- ── How it works ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold py-2"><i class="fas fa-info-circle me-2 text-primary"></i>How it works</div>
    <div class="card-body">
        <ol class="mb-0 ps-3 small">
            <li class="mb-1"><strong>Schema step</strong> (optional, run once): Creates the <code>lead_call_logs</code> table and adds <code>ssc_gpa</code>, <code>hsc_gpa</code>, <code>bachelor_subject</code>, <code>bachelor_cgpa</code> columns to the <code>leads</code> table.</li>
            <li class="mb-1"><strong>Data migration</strong>: Upload your <code>admin_67crm.sql</code> backup file. The tool will:
                <ol type="a" class="mt-1">
                    <li>Import the legacy data into staging tables (prefixed <code>crm_import_</code>) inside the current database — no separate database or extra privileges required.</li>
                    <li>Apply the schema step automatically if not done yet.</li>
                    <li>Copy all leads, notes, history, assignments, call logs and campus visits into the new system with proper value mapping.</li>
                    <li>Show final record counts so you can verify the import.</li>
                </ol>
            </li>
            <li><strong>Cleanup</strong> (optional): Drop all <code>crm_import_*</code> staging tables once you are satisfied.</li>
        </ol>
    </div>
</div>

<div class="row g-4">

    <!-- ── Step 1: Schema ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-semibold py-2 <?= $schema_done ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                <i class="fas fa-table me-2"></i>Step 1 – Apply Schema
                <?= $schema_done ? '<span class="badge bg-light text-success ms-2">Done</span>' : '' ?>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Runs <code>call-logs.sql</code> and <code>leads-gpa.sql</code> to add the new table and columns.
                    Safe to re-run — uses <code>IF NOT EXISTS</code>. You can skip this if you already ran it.
                </p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="schema">
                    <button type="submit" class="btn <?= $schema_done ? 'btn-outline-success' : 'btn-warning' ?> w-100">
                        <i class="fas fa-play me-2"></i><?= $schema_done ? 'Re-run Schema (safe)' : 'Run Schema Migration' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Step 2: Data Migration ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-semibold py-2 bg-primary text-white">
                <i class="fas fa-upload me-2"></i>Step 2 – Migrate Legacy Data
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Upload your <code>admin_67crm.sql</code> file (max <?= ini_get('upload_max_filesize') ?>).
                    Migration uses <code>INSERT IGNORE</code> — safe to re-run, duplicates are skipped.
                </p>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="migrate">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">admin_67crm.sql backup file</label>
                        <input type="file" name="sql_file" accept=".sql" class="form-control form-control-sm" required>
                        <div class="form-text">Only <code>.sql</code> files are accepted.</div>
                    </div>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Back up your live database before running this.</strong>
                        This action cannot be undone automatically.
                    </div>
                    <button type="submit" class="btn btn-primary w-100"
                            onclick="return confirm('Are you sure you want to start the migration? Make sure you have a database backup first.')">
                        <i class="fas fa-database me-2"></i>Upload &amp; Run Migration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Step 3: Cleanup ── -->
    <?php if ($crm_import_exists): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm border-danger">
            <div class="card-header fw-semibold py-2 bg-danger text-white">
                <i class="fas fa-trash-alt me-2"></i>Step 3 – Cleanup (optional)
            </div>
            <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
                <p class="mb-0 text-muted small">
                    The <code>crm_import_*</code> staging tables are no longer needed once migration is verified.
                    Drop them to free up disk space.
                </p>
                <form method="post" class="flex-shrink-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cleanup">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Drop all crm_import_* staging tables? This cannot be undone.')">
                        <i class="fas fa-trash-alt me-1"></i>Drop Staging Tables
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif ?>

</div><!-- /row -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
