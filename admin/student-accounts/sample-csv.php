<?php
/**
 * Student Accounts – Bulk Import Sample CSV
 *
 * Streams a ready-to-fill sample CSV that documents every column accepted by
 * the bulk CSV importer (bulk-import-process.php). The header row matches the
 * keys recognised by bip_parse_csv_row(); the two example rows show a
 * merit-based and a fixed (flat monthly) student so admins can see how the
 * "Payment Type" / "Monthly Payment" columns work.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_create');

$filename = 'student-accounts-bulk-import-sample.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'wb');

// UTF-8 BOM so Excel opens the file with correct encoding.
fwrite($out, "\xEF\xBB\xBF");

// Column order mirrors the headings requested by the accounts office.
$headers = [
    'Student ID',
    'Student Name',
    'Program',
    'Beginning Semester',
    'Payment Type',
    'Admission Fee',
    'English Language Course Fee',
    'Miscellaneous/Semester Fee',
    'Concession',
    'Monthly Payment',
    'Batch No.',
    'Total Semesters',
    'Registration Fee',
    'Tuition Fee/Credit',
    'Total Fee',
    'Payable Amount',
    'Payment Start Month',
];
fputcsv($out, $headers, ',', '"', '');

// Example 1 – Merit based student (monthly fee is calculated, Monthly Payment ignored).
fputcsv($out, [
    '02826105101071',          // Student ID (leading zero preserved)
    'Abdullah Al Mamun',
    'BSc in Computer Science & Engineering',
    'Spring 2026',
    'Merit based',
    '25000',
    '12000',
    '40000',
    '0',
    '0',
    '52',
    '12',
    '24000',
    '360000',
    '477000',
    '477000',
    '01-2026',
], ',', '"', '');

// Example 2 – Fixed payment student (flat Monthly Payment that never changes automatically).
fputcsv($out, [
    '2826105101072',           // Student ID (no leading zero – also accepted)
    'Fatema Khatun',
    'BBA',
    'Spring 2026',
    'Fixed',
    '25000',
    '12000',
    '40000',
    '50000',
    '6500',
    '52',
    '12',
    '24000',
    '300000',
    '417000',
    '367000',
    '01-2026',
], ',', '"', '');

fclose($out);
exit;
