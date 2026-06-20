<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';

$search     = trim($_GET['search'] ?? '');
$f_status   = $_GET['status'] ?? '';
$f_source   = $_GET['source'] ?? '';
$f_dept     = (int)($_GET['dept'] ?? 0);
$f_sem      = trim($_GET['semester'] ?? '');
$f_degree   = $_GET['degree'] ?? '';
$f_user     = (int)($_GET['user_id'] ?? 0);
$f_sort     = $_GET['sort'] ?? 'date_desc';
$f_followup = $_GET['followup'] ?? '';

$valid_statuses = array_keys(leads_all_statuses());
$valid_sources  = ['online', 'campus_visit', 'agent', 'f2f_marketing', 'facebook'];
$valid_sorts    = ['date_desc', 'date_asc', 'name_asc', 'name_desc', 'status_asc', 'followup_asc'];

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(l.lead_number LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.current_city LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 'l.status = ?';
    $params[] = $f_status;
}
if (in_array($f_source, $valid_sources, true)) {
    $where[]  = 'l.source = ?';
    $params[] = $f_source;
}
if ($f_dept > 0) {
    $where[]  = 'l.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_sem !== '') {
    $where[]  = 'l.preferred_semester = ?';
    $params[] = $f_sem;
}
if (in_array($f_degree, ['bachelor', 'master'], true)) {
    $where[]  = 'l.degree_type = ?';
    $params[] = $f_degree;
}
if ($f_user > 0) {
    $where[]  = 'EXISTS (SELECT 1 FROM lead_assignments la WHERE la.lead_id = l.id AND la.user_id = ?)';
    $params[] = $f_user;
}
if ($f_followup === 'today') {
    $where[] = 'l.next_followup_date = CURDATE()';
}
if ($f_followup === 'overdue') {
    $where[] = "(l.next_followup_date < CURDATE() AND l.status NOT IN ('converted','not_interested'))";
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sort_sql = match (in_array($f_sort, $valid_sorts, true) ? $f_sort : 'date_desc') {
    'date_asc'     => 'l.created_at ASC',
    'name_asc'     => 'l.first_name ASC, l.last_name ASC',
    'name_desc'    => 'l.first_name DESC, l.last_name DESC',
    'status_asc'   => 'l.status ASC',
    'followup_asc' => 'CASE WHEN l.next_followup_date IS NULL THEN 1 ELSE 0 END, l.next_followup_date ASC',
    default        => 'l.created_at DESC',
};

$sql = 'SELECT l.*,
               d.name      AS dept_name,
               p.program_name,
               u.full_name AS assigned_to_name
        FROM leads l
        LEFT JOIN dept_departments d       ON d.id = l.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = l.program_id
        LEFT JOIN users u                  ON u.id = l.assigned_to'
     . $where_sql
     . ' ORDER BY ' . $sort_sql;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

$departments = db()->query('SELECT id, name FROM dept_departments ORDER BY name ASC')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$staff_users = db()->query('SELECT id, full_name FROM users ORDER BY full_name ASC')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$sort_labels = [
    'date_desc'    => 'Date: Newest',
    'date_asc'     => 'Date: Oldest',
    'name_asc'     => 'Name: A–Z',
    'name_desc'    => 'Name: Z–A',
    'status_asc'   => 'By Status',
    'followup_asc' => 'Follow-up Date',
];

$active_filters = [];
if ($search !== '') {
    $active_filters['Search'] = $search;
}
if ($f_status !== '') {
    $active_filters['Status'] = leads_status_label($f_status);
}
if ($f_source !== '') {
    $active_filters['Source'] = leads_source_label($f_source);
}
if ($f_dept > 0 && isset($departments[$f_dept])) {
    $active_filters['Department'] = $departments[$f_dept];
}
if ($f_degree !== '') {
    $active_filters['Degree'] = ucfirst($f_degree);
}
if ($f_sem !== '') {
    $active_filters['Semester'] = $f_sem;
}
if ($f_user > 0 && isset($staff_users[$f_user])) {
    $active_filters['Assignee'] = $staff_users[$f_user];
}
if ($f_followup !== '') {
    $active_filters['Follow-up'] = $f_followup === 'today' ? "Today's Follow-ups" : 'Overdue Follow-ups';
}
$active_filters['Sort'] = $sort_labels[$f_sort] ?? $sort_labels['date_desc'];

$filter_qs = http_build_query(array_filter([
    'search'   => $search,
    'status'   => $f_status,
    'source'   => $f_source,
    'dept'     => $f_dept ?: '',
    'semester' => $f_sem,
    'degree'   => $f_degree,
    'user_id'  => $f_user ?: '',
    'sort'     => $f_sort !== 'date_desc' ? $f_sort : '',
    'followup' => $f_followup,
]));

$back_url = APP_URL . '/leads/index.php' . ($filter_qs !== '' ? '?' . $filter_qs : '');
$page_title = 'Printable Leads';
$logo_url = function_exists('acc_university_logo_url') ? acc_university_logo_url() : rtrim(APP_URL, '/') . '/assets/img/logo/logo-black-sm.png';
$university_address = function_exists('acc_university_address') ? acc_university_address() : '';
$university_website = function_exists('acc_university_website') ? acc_university_website() : '';
$printed_at = date('d M Y, h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; font-size: 12px; line-height: 1.35; background: #eef2f7; color: #1f2937; }
        .screen-controls {
            position: sticky; top: 0; z-index: 1000;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 10px 20px; background: #1e3a5f; color: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
        }
        .screen-controls .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .screen-controls .btn-linkish {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 6px; text-decoration: none; color: #fff; border: 1px solid transparent;
        }
        .screen-controls .btn-print { background: #2563eb; }
        .screen-controls .btn-back { background: #64748b; }
        .print-wrapper { padding: 18px; }
        .print-sheet {
            width: 100%; max-width: 1200px; margin: 0 auto; background: #fff;
            padding: 18px 20px 24px; box-shadow: 0 4px 18px rgba(15, 23, 42, .12);
        }
        .doc-header {
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            border-bottom: 2px solid #1e3a5f; padding-bottom: 12px; margin-bottom: 14px;
        }
        .brand { display: flex; align-items: center; gap: 14px; }
        .brand img { max-height: 52px; max-width: 70px; object-fit: contain; }
        .brand h1 { margin: 0; font-size: 20px; color: #1e3a5f; text-transform: uppercase; }
        .brand p { margin: 2px 0 0; color: #4b5563; font-size: 11px; }
        .doc-meta { text-align: right; font-size: 11px; }
        .doc-meta strong { color: #1e3a5f; }
        .summary-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px;
        }
        .summary-card {
            border: 1px solid #dbe3ef; border-radius: 8px; padding: 12px 14px; background: #f8fafc;
        }
        .summary-card h2 {
            margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #1e3a5f;
        }
        .summary-card table { width: 100%; border-collapse: collapse; }
        .summary-card td { padding: 4px 0; vertical-align: top; }
        .summary-card td:first-child { width: 130px; color: #475569; font-weight: 700; }
        .table-wrap { overflow: hidden; border: 1px solid #dbe3ef; border-radius: 8px; }
        table.print-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .print-table th {
            background: #1e3a5f; color: #fff; border: 1px solid #1e3a5f;
            padding: 7px 6px; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap;
        }
        .print-table td { border: 1px solid #dbe3ef; padding: 6px; vertical-align: top; }
        .print-table tbody tr:nth-child(even) { background: #f8fafc; }
        .muted { color: #64748b; }
        .text-nowrap { white-space: nowrap; }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .empty-state {
            padding: 28px; text-align: center; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b;
        }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body { background: #fff; font-size: 10px; }
            .screen-controls { display: none !important; }
            .print-wrapper { padding: 0; }
            .print-sheet { max-width: none; box-shadow: none; padding: 0; }
            .summary-grid { gap: 8px; }
            .summary-card { break-inside: avoid; }
            .table-wrap { border: none; }
            .print-table thead { display: table-header-group; }
            .print-table tr, .print-table td, .print-table th { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="screen-controls">
    <div>
        <strong><?= number_format(count($leads)) ?></strong> lead(s) ready to print
    </div>
    <div class="actions">
        <button type="button" class="btn-linkish btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
        <a href="<?= $back_url ?>" class="btn-linkish btn-back"><i class="fas fa-arrow-left"></i> Back to Leads</a>
    </div>
</div>

<div class="print-wrapper">
    <div class="print-sheet">
        <div class="doc-header">
            <div class="brand">
                <?php if ($logo_url): ?>
                <img src="<?= h($logo_url) ?>" alt="University Logo">
                <?php endif; ?>
                <div>
                    <h1>Lead Management Report</h1>
                    <?php if ($university_address !== ''): ?><p><?= h($university_address) ?></p><?php endif; ?>
                    <?php if ($university_website !== ''): ?><p><?= h($university_website) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="doc-meta">
                <div><strong>Printed:</strong> <?= h($printed_at) ?></div>
                <div><strong>Total Leads:</strong> <?= number_format(count($leads)) ?></div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <h2>Applied Filters</h2>
                <table>
                    <tbody>
                    <?php foreach ($active_filters as $label => $value): ?>
                        <tr>
                            <td><?= h($label) ?></td>
                            <td><?= h($value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="summary-card">
                <h2>Report Notes</h2>
                <table>
                    <tbody>
                        <tr>
                            <td>Module</td>
                            <td>Lead Management</td>
                        </tr>
                        <tr>
                            <td>Generated By</td>
                            <td><?= h(auth_user()['full_name'] ?? 'System User') ?></td>
                        </tr>
                        <tr>
                            <td>Result Set</td>
                            <td><?= count($leads) > 0 ? 'Filtered lead list' : 'No matching leads found' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (empty($leads)): ?>
        <div class="empty-state">
            <i class="fas fa-search fa-2x mb-2"></i>
            <div>No leads found for the selected filters.</div>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="print-table">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Lead No</th>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Degree / Program</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Follow-up</th>
                        <th>Assigned To</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $index => $lead): ?>
                    <?php
                    $is_terminal = in_array($lead['status'], ['converted', 'not_interested'], true);
                    $followup_text = '—';
                    if (!empty($lead['next_followup_date'])) {
                        $today = date('Y-m-d');
                        if ($lead['next_followup_date'] < $today) {
                            $followup_text = 'Overdue (' . date('d M Y', strtotime($lead['next_followup_date'])) . ')';
                        } elseif ($lead['next_followup_date'] === $today) {
                            $followup_text = 'Today';
                        } else {
                            $followup_text = date('d M Y', strtotime($lead['next_followup_date']));
                        }
                    } elseif ($is_terminal) {
                        $followup_text = 'Done';
                    }
                    ?>
                    <tr>
                        <td class="text-center"><?= $index + 1 ?></td>
                        <td class="text-nowrap"><?= h($lead['lead_number']) ?></td>
                        <td>
                            <strong><?= h(trim($lead['first_name'] . ' ' . $lead['last_name'])) ?></strong>
                            <?php if (!empty($lead['current_city'])): ?>
                            <div class="muted"><?= h($lead['current_city']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h($lead['phone']) ?></div>
                            <?php if (!empty($lead['email'])): ?><div class="muted"><?= h($lead['email']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div><?= h(ucfirst($lead['degree_type'])) ?></div>
                            <?php if (!empty($lead['dept_name']) || !empty($lead['program_name'])): ?>
                            <div class="muted"><?= h($lead['dept_name'] ?? '') ?><?= !empty($lead['program_name']) ? ' › ' . h($lead['program_name']) : '' ?></div>
                            <?php endif; ?>
                            <?php if (!empty($lead['preferred_semester'])): ?><div class="muted"><?= h($lead['preferred_semester']) ?></div><?php endif; ?>
                        </td>
                        <td><?= h(leads_status_label($lead['status'])) ?></td>
                        <td><?= h(leads_source_label($lead['source'])) ?></td>
                        <td>
                            <div><?= h($followup_text) ?></div>
                            <?php if (!empty($lead['followup_notes'])): ?><div class="muted"><?= h($lead['followup_notes']) ?></div><?php endif; ?>
                        </td>
                        <td><?= h($lead['assigned_to_name'] ?? '—') ?></td>
                        <td class="text-nowrap"><?= !empty($lead['created_at']) ? h(date('d M Y', strtotime($lead['created_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
