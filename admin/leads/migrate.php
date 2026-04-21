<?php
/**
 * Legacy CRM Migration – web UI
 * Lets a super-admin run the admin_67crm → primeprototype migration
 * entirely from the browser, without needing command-line access.
 *
 * POST actions
 *   schema  – run call-logs.sql + leads-gpa.sql (schema only, no data)
 *   migrate – upload admin_67crm.sql, create crm_import DB, import it,
 *             then run migrate-from-67crm.sql
 *   cleanup – DROP DATABASE crm_import
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
        // Escape sequences inside strings
        if ($ch === '\\' && ($in_single || $in_double)) {
            $current .= $ch . $next; $i += 2; continue;
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
            // Ignore "Column already exists" (1060) – idempotent ALTERs
            if (strpos($e->getMessage(), '1060') !== false) {
                $executed++; continue;
            }
            return ['ok' => false, 'executed' => $executed, 'error' => $e->getMessage() . "\n\nStatement:\n" . substr($stmt, 0, 500)];
        }
    }

    return ['ok' => true, 'executed' => $executed, 'error' => null];
}

/**
 * Open a PDO connection to MySQL without selecting a database.
 * Uses the same credentials as the main app.
 */
function db_server(): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
}

/**
 * Open a PDO connection to a specific database (crm_import).
 */
function db_crm(): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=crm_import;charset=utf8mb4', DB_HOST, DB_PORT);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
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
    $server_pdo = db_server();
    $dbs = $server_pdo->query("SHOW DATABASES LIKE 'crm_import'")->fetchAll();
    $crm_import_exists = !empty($dbs);
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
            $results[] = ['step' => 'Upload', 'ok' => false, 'msg' => 'No file uploaded or upload error (code ' . ($upload['error'] ?? '?') . '). Maximum upload size on this server: ' . ini_get('upload_max_filesize') . '.'];
        } else {
            $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $results[] = ['step' => 'Upload', 'ok' => false, 'msg' => 'Only .sql files are accepted.'];
            } else {
                $results[] = ['step' => 'Upload', 'ok' => true, 'msg' => '✔ File "' . h($upload['name']) . '" received (' . number_format($upload['size'] / 1024, 1) . ' KB).'];

                // ── Step A: Create crm_import database ───────────────────────
                $step_ok = true;
                try {
                    $srv = db_server();
                    $srv->exec('CREATE DATABASE IF NOT EXISTS `crm_import` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $results[] = ['step' => 'Create crm_import DB', 'ok' => true, 'msg' => '✔ Database crm_import is ready.'];
                    $crm_import_exists = true;
                } catch (PDOException $e) {
                    $results[] = ['step' => 'Create crm_import DB', 'ok' => false, 'msg' => '✘ Could not create crm_import database: ' . $e->getMessage() . '. Make sure the database user has CREATE privilege.'];
                    $step_ok = false;
                }

                // ── Step B: Import uploaded SQL into crm_import ───────────────
                if ($step_ok) {
                    $sql_content = file_get_contents($upload['tmp_name']);
                    if ($sql_content === false) {
                        $results[] = ['step' => 'Import into crm_import', 'ok' => false, 'msg' => '✘ Could not read uploaded file.'];
                        $step_ok = false;
                    } else {
                        try {
                            $crm_pdo = db_crm();
                            $res = sql_exec_all($crm_pdo, $sql_content);
                            if ($res['ok']) {
                                $results[] = ['step' => 'Import into crm_import', 'ok' => true, 'msg' => "✔ Executed {$res['executed']} statement(s) — legacy data loaded."];
                            } else {
                                $results[] = ['step' => 'Import into crm_import', 'ok' => false, 'msg' => '✘ ' . $res['error']];
                                $step_ok = false;
                            }
                        } catch (PDOException $e) {
                            $results[] = ['step' => 'Import into crm_import', 'ok' => false, 'msg' => '✘ ' . $e->getMessage()];
                            $step_ok = false;
                        }
                    }
                }

                // ── Step C: Run schema files if not yet done ──────────────────
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

                // ── Step D: Run migrate-from-67crm.sql ────────────────────────
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

                // ── Step E: Record counts ─────────────────────────────────────
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
            $srv = db_server();
            $srv->exec('DROP DATABASE IF EXISTS `crm_import`');
            $results[] = ['step' => 'Cleanup', 'ok' => true, 'msg' => '✔ crm_import database has been dropped.'];
            $crm_import_exists = false;
        } catch (PDOException $e) {
            $results[] = ['step' => 'Cleanup', 'ok' => false, 'msg' => '✘ ' . $e->getMessage()];
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
                    <span class="badge bg-info fs-6 px-3 py-2"><i class="fas fa-database me-1"></i>crm_import Exists</span>
                    <span class="text-muted small">Staging DB is present. You may clean it up after migration.</span>
                <?php else: ?>
                    <span class="badge bg-secondary fs-6 px-3 py-2"><i class="fas fa-database me-1"></i>No crm_import</span>
                    <span class="text-muted small">Staging DB will be created during migration.</span>
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
                    <li>Create a temporary <code>crm_import</code> database and import the file into it.</li>
                    <li>Apply the schema step automatically if not done yet.</li>
                    <li>Copy all leads, notes, history, assignments, call logs and campus visits into the new system with proper value mapping.</li>
                    <li>Show final record counts so you can verify the import.</li>
                </ol>
            </li>
            <li><strong>Cleanup</strong> (optional): Drop the <code>crm_import</code> staging database once you are satisfied.</li>
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
                    The <code>crm_import</code> staging database is no longer needed once migration is verified.
                    Drop it to free up disk space.
                </p>
                <form method="post" class="flex-shrink-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cleanup">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Drop the crm_import staging database? This cannot be undone.')">
                        <i class="fas fa-trash-alt me-1"></i>Drop crm_import Database
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif ?>

</div><!-- /row -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
