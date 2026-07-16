<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_access('users', 'can_create');

$page_title = 'Bulk Import Users (CSV)';
$errors        = [];
$import_summary = null;

// Default password for all bulk-created users (per requirement).
const BULK_DEFAULT_PASSWORD = '12345678';

// Groups the current admin is allowed to assign as the primary group.
$groups = db()->query('SELECT id, name, is_super FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();

$normalize_header = static function (string $header): string {
    return strtolower(trim(str_replace(['-', ' '], '_', $header)));
};

// Turn an arbitrary name/id into a safe username base: \w only, lowercase.
$slug_username = static function (string $value): string {
    $value = strtolower($value);
    // Transliterate common separators to nothing, keep alphanumerics + underscore.
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    return $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $primary_group_id = (int)($_POST['primary_group_id'] ?? 0);
    $email_domain     = strtolower(trim($_POST['email_domain'] ?? ''));
    $email_domain     = ltrim($email_domain, '@');
    $employee_type    = in_array($_POST['employee_type'] ?? '', ['administrative', 'educational'], true)
                        ? $_POST['employee_type'] : '';

    // Validate the chosen group.
    $group_row = null;
    if ($primary_group_id > 0) {
        $gs = db()->prepare('SELECT id, name, is_super FROM user_groups WHERE id = ? AND is_active = 1');
        $gs->execute([$primary_group_id]);
        $group_row = $gs->fetch();
    }
    if (!$group_row) {
        $errors[] = 'Please select a valid user group to assign.';
    } elseif ($group_row['is_super'] && !is_super_admin()) {
        $errors[] = 'You cannot assign users to the Super Admin group.';
    }

    if ($email_domain === '') {
        $errors[] = 'Please provide an email domain (used to generate a general email for each user).';
    } elseif (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $email_domain)) {
        $errors[] = 'Invalid email domain.';
    }

    if (empty($errors) && (!isset($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
        $errors[] = 'Please upload a valid CSV file.';
    }

    if (empty($errors)) {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            $errors[] = 'Unable to read uploaded CSV file.';
        } else {
            $headers = fgetcsv($fh, 0, ',', '"', '');
            if ($headers === false || empty($headers)) {
                $errors[] = 'CSV header row is missing.';
            } else {
                // Strip a possible UTF-8 BOM from the first header cell.
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
                }

                $header_map = [];
                foreach ($headers as $idx => $header) {
                    $header_map[$normalize_header((string)$header)] = $idx;
                }

                // Accept a few header aliases.
                $col_id   = $header_map['employee_id'] ?? ($header_map['emp_id'] ?? ($header_map['id'] ?? null));
                $col_name = $header_map['employee_name'] ?? ($header_map['name'] ?? ($header_map['full_name'] ?? null));
                $col_desig = $header_map['designation'] ?? ($header_map['title'] ?? null);
                $col_dept = $header_map['department'] ?? ($header_map['dept'] ?? null);

                if ($col_name === null) {
                    $errors[] = 'Missing required column: Employee Name.';
                }

                if (empty($errors)) {
                    // Preload active staff departments for matching (name -> row).
                    $staff_dept_rows = db()->query('SELECT id, name, type FROM staff_departments WHERE is_active = 1')->fetchAll();
                    $staff_dept_by_name = [];
                    foreach ($staff_dept_rows as $sd) {
                        $staff_dept_by_name[strtolower(trim((string)$sd['name']))] = $sd;
                    }

                    $db = db();
                    $insert_user = $db->prepare(
                        'INSERT INTO users (group_id, username, email, password, full_name, is_active)
                         VALUES (?,?,?,?,?,1)'
                    );
                    $insert_assign = $db->prepare(
                        'INSERT IGNORE INTO user_group_assignments (user_id, group_id, is_primary) VALUES (?,?,1)'
                    );
                    $insert_profile = $db->prepare(
                        'INSERT INTO staff_profiles (user_id, employee_id, department_type, staff_dept_id, designation)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            employee_id = VALUES(employee_id),
                            department_type = VALUES(department_type),
                            staff_dept_id = VALUES(staff_dept_id),
                            designation = VALUES(designation)'
                    );

                    $password_hash = password_hash(BULK_DEFAULT_PASSWORD, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

                    // Track usernames/emails already used within this batch to avoid collisions.
                    $used_usernames = [];
                    $used_emails    = [];

                    $username_taken = static function (string $u) use ($db, &$used_usernames): bool {
                        if (isset($used_usernames[$u])) return true;
                        $st = $db->prepare('SELECT id FROM users WHERE username = ?');
                        $st->execute([$u]);
                        return (bool)$st->fetch();
                    };
                    $email_taken = static function (string $e) use ($db, &$used_emails): bool {
                        if (isset($used_emails[$e])) return true;
                        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
                        $st->execute([$e]);
                        return (bool)$st->fetch();
                    };

                    $created     = 0;
                    $failed      = 0;
                    $row_no      = 1;
                    $row_errors  = [];
                    $created_rows = [];

                    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                        $row_no++;

                        $emp_id  = $col_id   !== null ? trim((string)($row[$col_id]   ?? '')) : '';
                        $name    = $col_name !== null ? trim((string)($row[$col_name] ?? '')) : '';
                        $desig   = $col_desig !== null ? trim((string)($row[$col_desig] ?? '')) : '';
                        $dept    = $col_dept !== null ? trim((string)($row[$col_dept]  ?? '')) : '';

                        // Skip fully blank lines.
                        if ($emp_id === '' && $name === '' && $desig === '' && $dept === '') {
                            continue;
                        }

                        if ($name === '') {
                            $failed++;
                            $row_errors[] = "Row {$row_no}: Employee Name is required.";
                            continue;
                        }

                        // Build a unique username base from the name, falling back to employee id.
                        $base = $slug_username($name);
                        if (strlen($base) < 3) {
                            $base = $slug_username($name . $emp_id);
                        }
                        if (strlen($base) < 3) {
                            $base = 'user' . $slug_username($emp_id);
                        }
                        $base = substr($base, 0, 55); // leave room for a numeric suffix (max 60)
                        if ($base === '' || !preg_match('/^\w/', $base)) {
                            $base = 'user' . $row_no;
                        }

                        $username = $base;
                        $suffix   = 1;
                        while ($username_taken($username)) {
                            $suffix++;
                            $username = substr($base, 0, 55) . $suffix;
                        }

                        // Build a unique general email from the username + chosen domain.
                        $email = $username . '@' . $email_domain;
                        $esuffix = 1;
                        while ($email_taken($email)) {
                            $esuffix++;
                            $email = $username . $esuffix . '@' . $email_domain;
                        }
                        if (strlen($email) > 191) {
                            $failed++;
                            $row_errors[] = "Row {$row_no}: generated email exceeds 191 characters.";
                            continue;
                        }

                        // Match department to a staff department (optional).
                        $dept_type = $employee_type !== '' ? $employee_type : null;
                        $staff_dept_id = null;
                        if ($dept !== '') {
                            $match = $staff_dept_by_name[strtolower($dept)] ?? null;
                            if ($match) {
                                $staff_dept_id = (int)$match['id'];
                                $dept_type = $match['type'];
                            } else {
                                $row_errors[] = "Row {$row_no}: department '{$dept}' not found in Staff Departments; created without a linked department.";
                            }
                        }

                        try {
                            $db->beginTransaction();

                            $insert_user->execute([
                                (int)$group_row['id'],
                                $username,
                                $email,
                                $password_hash,
                                $name,
                            ]);
                            $new_user_id = (int)$db->lastInsertId();

                            $insert_assign->execute([$new_user_id, (int)$group_row['id']]);

                            // Only create a staff profile if we have staff data to store.
                            if ($emp_id !== '' || $desig !== '' || $staff_dept_id !== null || $dept_type !== null) {
                                $insert_profile->execute([
                                    $new_user_id,
                                    $emp_id !== '' ? $emp_id : null,
                                    $dept_type,
                                    $staff_dept_id,
                                    $desig !== '' ? $desig : null,
                                ]);
                            }

                            $db->commit();
                        } catch (Throwable $ex) {
                            if ($db->inTransaction()) $db->rollBack();
                            $failed++;
                            $row_errors[] = "Row {$row_no}: failed to create user ({$name}).";
                            continue;
                        }

                        // Reserve within-batch.
                        $used_usernames[$username] = true;
                        $used_emails[$email] = true;

                        log_change('users', 'CREATE', $new_user_id, $username, 'bulk_import', null, $group_row['name'],
                            "User bulk-imported into group: " . $group_row['name']);

                        $created++;
                        $created_rows[] = [
                            'name'     => $name,
                            'username' => $username,
                            'email'    => $email,
                            'emp_id'   => $emp_id,
                        ];
                    }

                    $import_summary = [
                        'created'      => $created,
                        'failed'       => $failed,
                        'row_errors'   => $row_errors,
                        'created_rows' => $created_rows,
                    ];

                    if ($created > 0) {
                        flash_set('success', "Bulk import complete. Created {$created} user(s)." . ($failed > 0 ? " {$failed} row(s) failed." : ''));
                    } elseif ($failed > 0) {
                        flash_set('warning', "No users created. {$failed} row(s) failed validation.");
                    } else {
                        flash_set('info', 'No data rows found in CSV.');
                    }
                }
            }

            fclose($fh);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Bulk Import CSV</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Users
    </a>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-muted"></i>Import Users CSV</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label fw-medium">User Group <span class="text-danger">*</span></label>
                        <select name="primary_group_id" class="form-select" required style="border-radius:10px;">
                            <option value="">-- Select group to assign --</option>
                            <?php foreach ($groups as $g): ?>
                                <?php if ($g['is_super'] && !is_super_admin()) continue; ?>
                                <option value="<?= $g['id'] ?>" <?= (int)($_POST['primary_group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>>
                                    <?= h($g['name']) ?><?= $g['is_super'] ? ' (Super Admin)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">The CSV has no group column; all imported users are assigned to this group.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Email Domain <span class="text-danger">*</span></label>
                        <div class="input-group" style="max-width:360px;">
                            <span class="input-group-text">@</span>
                            <input type="text" name="email_domain" class="form-control"
                                   value="<?= h($_POST['email_domain'] ?? 'primeuniversity.ac.bd') ?>"
                                   placeholder="primeuniversity.ac.bd" required>
                        </div>
                        <small class="text-muted">A general email is auto-generated per user as <code>username@domain</code>.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Employee Type <small class="text-muted">(fallback)</small></label>
                        <select name="employee_type" class="form-select" style="max-width:360px;border-radius:10px;">
                            <?php $et = $_POST['employee_type'] ?? 'administrative'; ?>
                            <option value="">— None —</option>
                            <option value="administrative" <?= $et === 'administrative' ? 'selected' : '' ?>>Administrative</option>
                            <option value="educational" <?= $et === 'educational' ? 'selected' : '' ?>>Faculty</option>
                        </select>
                        <small class="text-muted">Used when the CSV Department does not match a Staff Department.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"
                            onclick="return confirm('Create users from this CSV? Default password will be 12345678.');">
                        <i class="fas fa-upload me-1"></i> Import CSV
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">CSV Format</h6>
            </div>
            <div class="card-body p-4">
                <p class="mb-2">Columns: <code>Employee ID</code>, <code>Employee Name</code>, <code>Designation</code>, <code>Department</code></p>
                <p class="mb-2"><strong>Employee Name</strong> is required. Other columns are optional.</p>
                <ul class="mb-2 ps-3">
                    <li>Default password for everyone: <code>12345678</code></li>
                    <li>Usernames are auto-generated from the name (made unique).</li>
                    <li>Emails are auto-generated as <code>username@domain</code>.</li>
                    <li><code>Department</code> is matched (by name) to a Staff Department.</li>
                    <li>Group is not read from the CSV — it is set above.</li>
                </ul>
                <hr>
                <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">Employee ID,Employee Name,Designation,Department
1001,Md. Abdul Karim,Assistant Professor,CSE
1002,Nasrin Akter,Senior Lecturer,EEE</pre>
            </div>
        </div>
    </div>
</div>

<?php if ($import_summary && !empty($import_summary['created_rows'])): ?>
<div class="card mt-3">
    <div class="card-header py-2 px-4"><strong>Created Users</strong> <span class="text-muted">(default password: 12345678)</span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($import_summary['created_rows'] as $i => $cr): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><?= $cr['emp_id'] !== '' ? h($cr['emp_id']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h($cr['name']) ?></td>
                        <td><code><?= h($cr['username']) ?></code></td>
                        <td><?= h($cr['email']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($import_summary && !empty($import_summary['row_errors'])): ?>
<div class="card mt-3">
    <div class="card-header py-2 px-4"><strong>Row Notes / Errors</strong></div>
    <div class="card-body">
        <ul class="mb-0 ps-3">
            <?php foreach ($import_summary['row_errors'] as $row_error): ?>
            <li><?= h($row_error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
