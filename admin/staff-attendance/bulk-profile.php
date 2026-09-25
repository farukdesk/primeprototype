<?php
/**
 * Staff Attendance – Bulk Profile Update.
 *
 * Lets attendance admins fix Designation, Dept./Section and Type of
 * Appointment for every staff member on one page, with a one-click prefill
 * from the August 2026 attendance statement roster. Rows are matched to the
 * reference roster by Employee ID first (exact digits, then the last three
 * digits) and the name is verified so that different people who share the
 * same name are never mixed up.
 *
 * Values are written to staff_profiles (designation, staff_dept_id,
 * job_type). Run admin/staff-attendance-appointment-types.sql first so the
 * job_type column accepts the "Regular" and "Probation" values used on the
 * statement.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
if (!att_is_admin()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/staff-attendance/index.php');
}

$page_title = 'Staff Attendance – Bulk Profile Update';

/** Type of Appointment options (kept in sync with SP_JOB_TYPES / job_type enum). */
$APPOINTMENT_TYPES = [
    'Regular', 'Permanent', 'Contractual', 'Probation', 'Probationary',
    'Ad-hoc', 'Master Role', 'Daily Basis',
];

$departments = att_departments();

// ── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $uids   = (array)($_POST['uid']         ?? []);
    $desigs = (array)($_POST['designation'] ?? []);
    $depts  = (array)($_POST['dept_id']     ?? []);
    $appts  = (array)($_POST['job_type']    ?? []);

    $dept_ids   = array_map(static fn($d) => (int)$d['id'], $departments);
    $dept_types = [];
    foreach ($departments as $d) $dept_types[(int)$d['id']] = (string)$d['type'];

    // Current values so only changed rows are written.
    $current = [];
    try {
        foreach (db()->query(
            'SELECT user_id, designation, staff_dept_id, job_type, department_type FROM staff_profiles'
        )->fetchAll() as $r) {
            $current[(int)$r['user_id']] = $r;
        }
    } catch (Throwable $e) {
        $current = [];
    }

    // Only users visible on the attendance report may be updated here.
    $allowed = [];
    foreach (att_staff_list() as $s) $allowed[(int)$s['id']] = true;

    $updated = 0;
    $errors  = 0;
    $upsert  = db()->prepare(
        'INSERT INTO staff_profiles (user_id, designation, staff_dept_id, job_type, department_type)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            designation     = VALUES(designation),
            staff_dept_id   = VALUES(staff_dept_id),
            job_type        = VALUES(job_type),
            department_type = COALESCE(staff_profiles.department_type, VALUES(department_type))'
    );

    foreach ($uids as $i => $raw_uid) {
        $uid = (int)$raw_uid;
        if ($uid <= 0 || !isset($allowed[$uid])) continue;

        $desig = trim((string)($desigs[$i] ?? ''));
        $desig = $desig !== '' ? mb_substr($desig, 0, 200) : null;
        $dept  = (int)($depts[$i] ?? 0);
        $dept  = in_array($dept, $dept_ids, true) ? $dept : null;
        $appt  = trim((string)($appts[$i] ?? ''));
        $appt  = in_array($appt, $APPOINTMENT_TYPES, true) ? $appt : null;

        $cur       = $current[$uid] ?? null;
        $cur_desig = $cur !== null ? ($cur['designation'] ?? null) : null;
        $cur_dept  = $cur !== null ? ((int)($cur['staff_dept_id'] ?? 0) ?: null) : null;
        $cur_appt  = $cur !== null ? ($cur['job_type'] ?? null) : null;
        if ($cur !== null && $cur_desig === $desig && $cur_dept === $dept && $cur_appt === $appt) {
            continue; // unchanged
        }

        // department_type only when currently unset – inferred from the department.
        $dtype = $dept !== null ? ($dept_types[$dept] ?? null) : null;

        try {
            $upsert->execute([$uid, $desig, $dept, $appt, $dtype]);
            $updated++;
            $changes = [];
            if ($cur_desig !== $desig) $changes[] = 'designation: ' . ($cur_desig ?? '—') . ' → ' . ($desig ?? '—');
            if ($cur_dept  !== $dept)  $changes[] = 'dept_id: ' . ($cur_dept ?? '—') . ' → ' . ($dept ?? '—');
            if ($cur_appt  !== $appt)  $changes[] = 'appointment: ' . ($cur_appt ?? '—') . ' → ' . ($appt ?? '—');
            log_change('staff-attendance', 'UPDATE', $uid, 'Bulk profile update', null, null, null,
                implode('; ', $changes));
        } catch (Throwable $e) {
            $errors++;
        }
    }

    if ($errors > 0) {
        flash_set('error', $updated . ' staff updated, but ' . $errors . ' row(s) could not be saved. '
            . 'If you set a "Regular" or "Probation" appointment, run the '
            . 'admin/staff-attendance-appointment-types.sql migration first.');
    } elseif ($updated > 0) {
        flash_set('success', 'Updated designation / dept / appointment for ' . $updated . ' staff member(s).');
    } else {
        flash_set('success', 'No changes to save – everything is already up to date.');
    }
    redirect(APP_URL . '/staff-attendance/bulk-profile.php?' . http_build_query(array_filter([
        'q'       => trim($_POST['q'] ?? '') ?: null,
        'missing' => ($_POST['missing'] ?? '') === '1' ? 1 : null,
    ])));
}

// ── Data ─────────────────────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$only_missing = ($_GET['missing'] ?? '') === '1';

$staff = att_staff_list(0, $search);

// Profile extras (designation / job_type live outside att_staff_list()).
$profiles = [];
try {
    foreach (db()->query('SELECT user_id, designation, staff_dept_id, job_type FROM staff_profiles')->fetchAll() as $r) {
        $profiles[(int)$r['user_id']] = $r;
    }
} catch (Throwable $e) {
    // older schema – treated as blank
}

if ($only_missing) {
    $staff = array_values(array_filter($staff, static function (array $s) use ($profiles): bool {
        $p = $profiles[(int)$s['id']] ?? [];
        return trim((string)($p['designation'] ?? '')) === ''
            || (int)($p['staff_dept_id'] ?? 0) === 0
            || trim((string)($p['job_type'] ?? '')) === '';
    }));
}

// ── Reference roster (August 2026 attendance statement) ─────────────────────
// [employee_id, name, designation, dept/section, type of appointment]
$AUG_ROSTER = [
    ['',    'Prof Dr Quazi Deen Mohd Khosru', 'Vice Chancellor', '', 'Regular'],
    ['502', 'Prof Dr Abdur Rahman', 'Pro-VC (Acting) & Treasurer', 'Treasurer', 'Regular'],
    ['110', 'Prof Dr Md Mustafa Kamal', 'Registrar', 'Admin', 'Contractual'],
    ['111', 'S. Tassadaque Ahmed', 'Academic, Legal, Estate & BOT Affairs', 'Admin', 'Contractual'],
    ['',    'Md. Razibul Islam', "Director, Students' Affairs", 'Admission', 'Regular'],
    ['157', 'Md Masud Karim', 'Deputy Director', 'Accounts', 'Regular'],
    ['127', 'Md Halimuzzaman Chowdhury', 'Deputy Director (CRHP)', 'Admin', 'Regular'],
    ['128', 'Dr Saida Ahmed', 'Medical Officer', 'Admin', 'Probation'],
    ['950', 'Md Omar Faruk', 'Deputy Director', 'IT', 'Ad-hoc'],
    ['132', 'Md. Belayet Hossain', 'Assistant Director', 'IT', 'Regular'],
    ['133', 'Tanzila Sultana', 'Assistant Director', 'IQAC', 'Regular'],
    ['129', 'Shirin Sultana', 'Assistant Director', 'CRHP', 'Regular'],
    ['185', 'Md. Monayem Hossain', 'Assistant CoE', 'CoE', 'Regular'],
    ['144', 'Asma Khanam', 'Assistant Registrar', 'Admin', 'Regular'],
    ['189', 'Md. Abu Bakar Siddique', 'PS to VC (Assistant Registrar)', 'Admin', 'Regular'],
    ['138', 'Anwar Hussain', 'Assistant Advisor (Legal & Estate)', 'Admin', 'Ad-hoc'],
    ['152', 'Laila Akhter', 'Accounts Officer', 'IQAC', 'Regular'],
    ['207', 'Md Nasirul Islam', 'Accounts Officer', 'Accounts', 'Regular'],
    ['272', 'Ashish Kumar Debnath', 'Accounts Officer', 'Accounts', 'Regular'],
    ['271', 'Md Jowel Rana', 'HoD Digital Marketing', 'Admission', 'Regular'],
    ['141', 'Jamila Khatun', 'Admission Officer', 'Admission', 'Regular'],
    ['194', 'Md. Sirajul Islam', 'Store Officer', 'Admin', 'Regular'],
    ['260', 'Naznin Naher', 'Admission Officer', 'Admission', 'Regular'],
    ['308', 'Md Zaman Ibne Aziz', 'Admin Officer', 'Admin', 'Contractual'],
    ['208', 'Tasnia Nasrin', 'Section Officer', 'Admin', 'Regular'],
    ['601', 'Rafiuzzaman', 'Section Officer', 'IQAC', 'Probation'],
    ['159', 'Md Ashikur Rahman', 'Admission Officer', 'Admission', 'Ad-hoc'],
    ['153', 'Md Niamul Haque', 'Admission Officer', 'Admission', 'Ad-hoc'],
    ['112', 'Md Azizul Islam', 'Deputy Librarian', 'Library', 'Regular'],
    ['136', 'Kamrunnaher', 'Assistant Librarian', 'Library', 'Regular'],
    ['182', 'Md. Abdus Salam', 'Section Officer', 'Business Admin', 'Regular'],
    ['408', 'Md Nazmul Haque', 'Section Officer', 'CSE', 'Regular'],
    ['155', 'Md Shariful Alam', 'Section Officer', 'EEE', 'Regular'],
    ['205', 'Sayada Lofna Akter', 'Section Officer', 'English', 'Regular'],
    ['397', 'Emrana Haque', 'Section Officer', 'Law', 'Regular'],
    ['414', 'Md Shafiul Islam', 'Section Officer', 'Admin', 'Regular'],
    ['158', 'Sheuly', 'Section Officer', 'Bangla', 'Ad-hoc'],
    ['223', 'Nur Hossain', 'Lab Assistant', 'EEE', 'Regular'],
    ['229', 'Md Manzarul Islam', 'Lab Assistant', 'EEE', 'Regular'],
    ['307', 'Md Sarowar Hossain', 'Lab Assistant', 'CE', 'Regular'],
    ['368', 'Md Mafizur Rahman', 'Lab Assistant', 'CSE', 'Ad-hoc'],
    ['399', 'Md Raju Ahmed', 'Lab Assistant', 'EEE', 'Regular'],
    ['621', 'Md Al-Amin', 'Lab Assistant', 'CE', 'Probation'],
    ['622', 'Nishat Nabila', 'Cataloguer', 'Library', 'Probation'],
    ['332', 'Rubel Mia', 'Book Sorter', 'Library', 'Regular'],
    ['932', 'Gobindra Chandra Dash', 'Sub Asst Engg', 'Admin', 'Regular'],
    ['269', 'Md Raihan Shikder', 'Accounts Asst', 'Accounts', 'Regular'],
    ['256', 'Md. Abdullah Al Mamun', 'OA cum CO', 'CoE', 'Regular'],
    ['396', 'Md Sahid Hasan', 'OA cum CO', 'Admin', 'Regular'],
    ['253', 'Md. Nasir Uddin', 'OA cum CO', 'Admin', 'Regular'],
    ['395', 'Md Anwar Hossain', 'PA to Treasurer', 'Accounts', 'Regular'],
    ['911', 'Md Fazlul Haque', 'PA to Registrar', 'Admin', 'Regular'],
    ['913', 'Sarowar Hossain', 'OA cum CO', 'CoE', 'Regular'],
    ['261', 'Md. Rasheduzzaman', 'Electrician', 'Admin', 'Regular'],
    ['393', 'Md Sazedul Islam', 'Electrician', 'Admin', 'Regular'],
    ['491', 'Md Abu Sayed', 'AC Technician', 'Admin', 'Regular'],
    ['305', 'Md. Sumon Howlader', 'Driver', 'Admin', 'Regular'],
    ['631', 'Sreemon Dong', 'Driver', 'Admin', 'Regular'],
    ['306', 'Rajesh Sangma', 'Driver', 'VC', 'Ad-hoc'],
    ['386', 'Md Saleh Ahmed Rubel', 'Plumber', 'Admin', 'Regular'],
    ['937', 'Md Shojol Khan', 'Lift Operator', 'Admin', 'Ad-hoc'],
    ['338', 'Rana Islam', 'MLSS', 'Admin', 'Regular'],
    ['331', 'Md Hashikul Alam', 'MLSS', 'CE', 'Regular'],
    ['333', 'AK Majharul Islam', 'MLSS', 'Bangla', 'Regular'],
    ['335', 'Sk Towhidul Islam', 'MLSS', 'CoE', 'Regular'],
    ['342', 'Md. Ashraful Islam', 'MLSS', 'CSE', 'Regular'],
    ['340', 'Saidur Rahman', 'MLSS', 'Admission', 'Regular'],
    ['411', 'Al Amin', 'MLSS', 'BoT', 'Regular'],
    ['413', 'Rekha Akter', 'MLSS', 'Admin', 'Regular'],
    ['496', 'Md Sojib Mondol', 'MLSS', 'Law', 'Contractual'],
    ['400', 'Md Hasanozzaman Sizer', 'MLSS', 'Admin', 'Contractual'],
    ['499', 'Md Rakib Hossen', 'MLSS', 'VC Office', 'Contractual'],
    ['341', 'Emam Hossain Ratul', 'MLSS', 'English', 'Ad-hoc'],
    ['336', 'Shakib Sajid Rahab', 'MLSS', 'EEE', 'Ad-hoc'],
    ['407', 'Abu Jahid Nabil', 'MLSS', 'Business', 'Ad-hoc'],
    ['339', 'Md Sagor Shikder', 'MLSS', 'Accounts', 'Ad-hoc'],
    ['951', 'Jahidul Haque', 'MLSS', 'Library', 'Ad-hoc'],
    ['350', 'Asma Begum', 'Cleaner', 'Admin', 'Regular'],
    ['352', 'Renu Begum', 'Cleaner', 'Admin', 'Regular'],
    ['354', 'Jamuna Begum', 'Cleaner', 'Admin', 'Contractual'],
    ['429', 'Sonia Akter', 'Cleaner', 'Admin', 'Contractual'],
    ['359', 'Salma Akter', 'Cleaner', 'Admin', 'Contractual'],
    ['369', 'Nur Naher Akter', 'Cook', 'Admin', 'Contractual'],
    ['424', 'Nupur Akter', 'Cleaner', 'Admin', 'Contractual'],
    ['425', 'Nilufa Begum', 'Cleaner', 'Admin', 'Contractual'],
    ['427', 'Kuchi', 'Cleaner', 'Admin', 'Contractual'],
    ['428', 'Hasina Begum', 'Cleaner', 'Admin', 'Contractual'],
    ['430', 'Nurjahan', 'Cleaner', 'Admin', 'Contractual'],
    ['334', 'Khadiza Begum', 'Cleaner', 'Admin', 'Contractual'],
    ['952', 'Md Salam Sarder', 'Cleaner', 'Admin', 'Contractual'],
    ['426', 'Mst Selina Begum', 'Cleaner', 'Admin', 'Contractual'],
    ['345', 'Mst Jesmine Akter', 'Cleaner', 'Admin', 'Ad-hoc'],
    // Security Section
    ['372', 'Md. Anwar Hossain', 'Security Guard', 'Admin', 'Contractual'],
    ['492', 'Md Shakil Mia', 'Security Guard', 'Admin', 'Contractual'],
    ['493', 'Md Sarwar Mia', 'Security Guard', 'Admin', 'Contractual'],
    ['494', 'Md Azadur Rahman', 'Security Guard', 'Admin', 'Contractual'],
    ['495', 'Md Nazmul Haque Khan', 'Security Guard', 'Admin', 'Contractual'],
    ['343', 'Hasi Begum', 'Security Guard', 'Admin', 'Contractual'],
    // Department of English
    ['109', 'Islam Md Hashanat', 'Professor, Dean', 'Department of English', 'Contractual'],
    ['563', 'Dr Md Abdul Awal', 'Associate Professor & Head', 'Department of English', 'Regular'],
    ['553', 'Md Jahidul Azad', 'Associate Professor', 'Department of English', 'Regular'],
    ['564', 'Rakib Uddin', 'Associate Professor', 'Department of English', 'Regular'],
    ['609', 'Prova Ummay Afzalen', 'Assistant Professor', 'Department of English', 'Regular'],
    ['608', 'Aysha Alam Talukder', 'Assistant Professor', 'Department of English', 'Regular'],
    ['610', 'Biddut Kumar Dutta', 'Assistant Professor', 'Department of English', 'Regular'],
    ['711', 'Mohd Jasim Uddin Khan', 'Assistant Professor', 'Department of English', 'Regular'],
    ['712', 'Alaul Alam', 'Lecturer', 'Department of English', 'Regular'],
    ['921', 'Susmita Barai', 'Lecturer in BDS & HEB', 'Department of English', 'Probation'],
    ['922', 'Suriya Yesmin Suchana', 'Lecturer in BDS & HEB', 'Department of English', 'Probation'],
    ['938', 'Maisha Chowdhury Snigdha', 'Lecturer in BDS & HEB', 'Department of English', 'Probation'],
    ['626', 'Rokaiya Abdullah Raka', 'Lecturer', 'Department of English', 'Probation'],
    ['638', 'Khadijatul Jannat Sumi', 'Lecturer', 'Department of English', 'Ad-hoc'],
    ['639', 'Md Nayeem Chowdhury', 'Lecturer', 'Department of English', 'Ad-hoc'],
    // Department of Education
    ['816', 'Mabia Momen', 'Lecturer & Head', 'Department of Education', 'Regular'],
    ['620', 'Abdullah-Al-Lipu', 'Lecturer', 'Department of Education', 'Ad-hoc'],
    // Department of Bangla
    ['503', 'Dr Rakibul Hassan', 'Professor & Head', 'Department of Bangla', 'Regular'],
    ['850', 'Md Al Amin', 'Assistant Professor', 'Department of Bangla', 'Regular'],
    ['883', 'Tahomina Akter Nova', 'Lecturer', 'Department of Bangla', 'Regular'],
    ['630', 'Most Marufa Akter', 'Lecturer', 'Department of Bangla', 'Ad-hoc'],
    // Department of Business Administration
    ['557', 'Dr. Md. Mahedi Hasan', 'Associate Prof. & Head', 'Department of Business Administration', 'Regular'],
    ['555', 'Dr Khurshida Pervin', 'Associate Professor', 'Department of Business Administration', 'Regular'],
    ['556', 'Nahid Farzana', 'Associate Professor', 'Department of Business Administration', 'Regular'],
    ['562', 'Md. Nazrul Islam', 'Assistant Professor', 'Department of Business Administration', 'Regular'],
    ['602', 'Md. Yeasir Arafat Bhuiyan', 'Assistant Professor', 'Department of Business Administration', 'Regular'],
    ['604', 'Saima Sultana', 'Assistant Professor', 'Department of Business Administration', 'Regular'],
    ['606', 'Md. Monir Hossain', 'Assistant Professor', 'Department of Business Administration', 'Regular'],
    ['707', 'Sujit Kumer Debnath', 'Assistant Professor', 'Department of Business Administration', 'Regular'],
    ['895', 'Abdullah Al Maruf', 'Lecturer', 'Department of Business Administration', 'Regular'],
    ['923', 'Noyon Biswas', 'Lecturer', 'Department of Business Administration', 'Regular'],
    ['624', 'Lamiya Mehjabin', 'Lecturer', 'Department of Business Administration', 'Probation'],
    ['625', 'Md Emon', 'Lecturer', 'Department of Business Administration', 'Probation'],
    ['628', 'Md Asib Hasan', 'Lecturer', 'Department of Business Administration', 'Ad-hoc'],
    // Department of CSE
    ['106', 'Colonel Md Shihabul Islam (Retd)', 'Associate Professor & Head', 'Department of CSE', 'Contractual'],
    ['560', 'Dr. Momtaz Begum Momo', 'Professor', 'Department of CSE', 'Regular'],
    ['906', 'Mst Ayesha Siddika', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['524', 'Md Abdur Rahim', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['525', 'Md Tareq Hasan', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['618', 'Md Mokhlesur Rahman', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['727', 'Khadiza Begum', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['803', 'Md R Rahman', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['736', 'Sanchita Rani Das', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['858', 'Rezoana Akter', 'Assistant Professor', 'Department of CSE', 'Regular'],
    ['905', 'Md Samrat Ali Abu Kawser', 'Lecturer', 'Department of CSE', 'Regular'],
    ['768', 'Omlan Jyoti Mondal', 'Lecturer', 'Department of CSE', 'Regular'],
    ['872', 'Papia Akter', 'Lecturer', 'Department of CSE', 'Regular'],
    ['904', 'Fahim Shahriar', 'Lecturer', 'Department of CSE', 'Regular'],
    ['927', 'Md Mahfuzur Rahman', 'Lecturer', 'Department of CSE', 'Regular'],
    ['928', 'Md Atikur Rahman', 'Lecturer', 'Department of CSE', 'Regular'],
    ['917', 'Nahian Sourov', 'Lecturer', 'Department of CSE', 'Ad-hoc'],
    ['623', 'Rafia Jannet Antora', 'Lecturer', 'Department of CSE', 'Probation'],
    // Department of EEE
    ['554', 'Md. Mostak Ahmed', 'Associate Professor & Head', 'Department of EEE', 'Regular'],
    ['720', 'Tanvir Ahmad Tarique', 'Assistant Professor', 'Department of EEE', 'Regular'],
    ['761', 'Sunirmal Kumar Biswas', 'Assistant Professor', 'Department of EEE', 'Regular'],
    ['772', 'Erona Khatun', 'Assistant Professor', 'Department of EEE', 'Regular'],
    ['815', 'Md Selim Reza', 'Assistant Professor', 'Department of EEE', 'Regular'],
    ['814', 'H.M Maruf Rahman Shuvo', 'Lecturer', 'Department of EEE', 'Regular'],
    ['885', 'Md Nazmul Islam', 'Lecturer', 'Department of EEE', 'Regular'],
    ['764', 'Mst Maksuda Khatun', 'Lecturer', 'Department of EEE', 'Regular'],
    ['896', 'Fahmida Brishti', 'Lecturer', 'Department of EEE', 'Regular'],
    ['925', 'Habibur Rahman Shipu', 'Lecturer', 'Department of EEE', 'Probation'],
    ['773', 'Manjur Muntasir', 'Lecturer', 'Department of EEE', 'Probation'],
    ['774', 'Muzakkir Ahmed', 'Lecturer', 'Department of EEE', 'Probation'],
    ['775', 'Md Saidur Rahman Abir', 'Lecturer', 'Department of EEE', 'Probation'],
    // Department of Civil Engineering
    ['522', 'Dr Md Iquebal Hossain', 'Asst Professor & Head', 'Department of Civil Engineering', 'Probation'],
    ['619', 'Abdul Aziz', 'Asst Professor', 'Department of Civil Engineering', 'Regular'],
    ['863', 'Nabila Islam Shorna', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['864', 'Sakhawat Hossain', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['874', 'Most Shuborna Khatun', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['875', 'Mohammad Atiqul Islam', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['877', 'Fahmida Akter', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['910', 'Md Shadman Sakib Chow.', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['920', 'Md Emran Hossain Emon', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    ['933', 'Sifwat Alvi Rahman', 'Lecturer', 'Department of Civil Engineering', 'Regular'],
    // Department of Law
    ['576', 'Dr Md Nahidul Islam', 'Associate Professor', 'Department of Law', 'Ad-hoc'],
    ['150', 'Al Amin', 'Associate Professor', 'Department of Law', 'Ad-hoc'],
    ['552', 'Golam Sarowar', 'Asst. Professor', 'Department of Law', 'Regular'],
    ['568', 'Md. Mostafijur Rahman', 'Asst. Professor & Head (In-charge)', 'Department of Law', 'Regular'],
    ['612', 'Mst. Shahina Ferdousi', 'Asst. Professor', 'Department of Law', 'Regular'],
    ['615', 'Sabina Yasmin', 'Asst Professor', 'Department of Law', 'Regular'],
    ['703', 'Md. Ariful Islam', 'Asst. Professor', 'Department of Law', 'Regular'],
    ['613', 'Ashiya Akter', 'Asst. Professor', 'Department of Law', 'Regular'],
    ['890', 'AFM Azif Aznani', 'Lecturer', 'Department of Law', 'Regular'],
    ['926', 'Faijul Islam', 'Lecturer', 'Department of Law', 'Regular'],
    ['627', 'Md Mursalin', 'Lecturer', 'Department of Law', 'Probation'],
    // Department of Fashion Design and Apparel Engineering
    ['574', 'Dr Md Kamrul Hasan', 'Associate Professor', 'Department of Fashion Design and Apparel Engineering', 'Ad-hoc'],
    ['398', 'Saba Farah Anchal', 'Lecturer', 'Department of Fashion Design and Apparel Engineering', 'Ad-hoc'],
    // Prime University Language School
    ['935', 'Sajma Akter', 'Lecturer in PULS', 'Prime University Language School', 'Regular'],
    ['936', 'Kanit Ahmed', 'Lecturer in PULS', 'Prime University Language School', 'Probation'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-users-gear me-2 text-primary"></i>Bulk Profile Update</h1>
        <p class="text-muted mb-0 small">Set Designation, Dept./Section and Type of Appointment for many staff at once.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/staff-attendance/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Attendance
        </a>
        <a href="<?= APP_URL ?>/staff-profiles/departments.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-sitemap me-1"></i> Manage Departments
        </a>
    </div>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold small mb-1">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Name, username or employee ID…" value="<?= h($search) ?>">
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="missing" value="1" id="f-missing" <?= $only_missing ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="f-missing">
                        Only staff missing designation, dept/section or appointment type
                    </label>
                </div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="<?= APP_URL ?>/staff-attendance/bulk-profile.php" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<form method="post" id="bulk-form">
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= h($search) ?>">
    <input type="hidden" name="missing" value="<?= $only_missing ? '1' : '' ?>">

    <div class="card" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-id-badge me-2 text-muted"></i>Staff on the attendance report
                <span class="badge bg-secondary ms-1"><?= count($staff) ?></span>
            </h6>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="opt-overwrite">
                    <label class="form-check-label small" for="opt-overwrite">Overwrite existing values</label>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btn-prefill">
                    <i class="fas fa-wand-magic-sparkles me-1"></i> Prefill from Aug 2026 statement
                </button>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-floppy-disk me-1"></i> Save All Changes
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="prefill-summary" class="alert alert-info m-3 mb-0 py-2 small d-none"></div>
            <?php if (empty($staff)): ?>
            <p class="text-muted p-4 mb-0">No staff found.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" id="bulk-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:4%;">#</th>
                            <th style="width:8%;">Emp&nbsp;ID</th>
                            <th style="width:22%;">Name</th>
                            <th style="width:26%;">Designation</th>
                            <th style="width:22%;">Dept./Section</th>
                            <th style="width:18%;">Type of Appointment</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $sl = 0; foreach ($staff as $s):
                        $uid  = (int)$s['id'];
                        $p    = $profiles[$uid] ?? [];
                        $desig = (string)($p['designation'] ?? '');
                        $dept  = (int)($p['staff_dept_id'] ?? 0);
                        $appt  = (string)($p['job_type'] ?? '');
                        $sl++;
                    ?>
                    <tr data-emp="<?= h((string)($s['employee_id'] ?? '')) ?>" data-name="<?= h((string)$s['full_name']) ?>">
                        <td class="text-muted small"><?= $sl ?></td>
                        <td><code><?= h((string)($s['employee_id'] ?? '') ?: '—') ?></code></td>
                        <td>
                            <?= h($s['full_name']) ?>
                            <div class="text-muted small"><code><?= h($s['username']) ?></code></div>
                            <input type="hidden" name="uid[]" value="<?= $uid ?>">
                        </td>
                        <td>
                            <input type="text" name="designation[]" class="form-control form-control-sm f-desig"
                                   maxlength="200" value="<?= h($desig) ?>" placeholder="Designation">
                        </td>
                        <td>
                            <select name="dept_id[]" class="form-select form-select-sm f-dept">
                                <option value="0">— none —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="job_type[]" class="form-select form-select-sm f-appt">
                                <option value="">— none —</option>
                                <?php foreach ($APPOINTMENT_TYPES as $t): ?>
                                <option value="<?= h($t) ?>" <?= $appt === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-3 px-4 d-flex justify-content-end">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-floppy-disk me-1"></i> Save All Changes
            </button>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';

    // Reference roster from the August 2026 attendance statement.
    var ROSTER = <?= json_encode(array_map(static fn(array $r) => [
        'id' => $r[0], 'name' => $r[1], 'desig' => $r[2], 'dept' => $r[3], 'appt' => $r[4],
    ], $AUG_ROSTER), JSON_UNESCAPED_UNICODE) ?>;

    function normName(s) {
        return String(s || '').toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ').trim();
    }
    // Name tokens without common honorifics, for fuzzy comparison.
    function nameTokens(s) {
        var skip = { md: 1, mst: 1, most: 1, mohd: 1, dr: 1, prof: 1, mr: 1, mrs: 1, ms: 1 };
        return normName(s).split(' ').filter(function (t) { return t && !skip[t]; });
    }
    function namesSimilar(a, b) {
        var ta = nameTokens(a), tb = nameTokens(b);
        if (!ta.length || !tb.length) return false;
        var small = ta.length <= tb.length ? ta : tb;
        var setB  = {};
        (ta.length <= tb.length ? tb : ta).forEach(function (t) { setB[t] = 1; });
        var hit = small.filter(function (t) { return setB[t]; }).length;
        return hit >= Math.max(1, small.length - 1) && hit >= 2 || (small.length === 1 && hit === 1);
    }
    function digits(s) {
        var d = String(s || '').replace(/\D+/g, '').replace(/^0+/, '');
        return d;
    }

    // Index roster by exact id-digits and by last-3 digits.
    var byId = {}, byLast3 = {}, byName = {};
    ROSTER.forEach(function (r) {
        var d = digits(r.id);
        if (d) {
            (byId[d] = byId[d] || []).push(r);
            var l3 = d.slice(-3);
            (byLast3[l3] = byLast3[l3] || []).push(r);
        }
        var n = normName(r.name);
        (byName[n] = byName[n] || []).push(r);
    });

    // Match one table row to a roster entry.
    // 1) exact employee-id digits + similar name;
    // 2) last-3 employee-id digits + similar name (device PINs often pad IDs);
    // 3) exact/similar unique name only when the row has no employee id.
    function matchRow(emp, name) {
        var d = digits(emp);
        var cands, i;
        if (d) {
            cands = byId[d] || [];
            for (i = 0; i < cands.length; i++) if (namesSimilar(cands[i].name, name)) return cands[i];
            if (cands.length === 1 && !normName(name)) return cands[0];
            cands = byLast3[d.slice(-3)] || [];
            for (i = 0; i < cands.length; i++) if (namesSimilar(cands[i].name, name)) return cands[i];
            return null; // has an id that matches nobody – do NOT fall back to name-only
        }
        cands = byName[normName(name)] || [];
        if (cands.length === 1) return cands[0];
        if (cands.length > 1) return null; // ambiguous duplicate name – skip
        // fuzzy unique-name match
        var hits = ROSTER.filter(function (r) { return namesSimilar(r.name, name); });
        return hits.length === 1 ? hits[0] : null;
    }

    // Match a roster dept/section label to a <select> option.
    var DEPT_ALIASES = {
        'ce': 'civil engineering', 'coe': 'coe', 'business admin': 'business administration',
        'business': 'business administration', 'puls': 'prime university language school',
        'vc office': 'vc', 'bot': 'bot'
    };
    function normDept(s) {
        var n = normName(s).replace(/^department of /, '').replace(/^dept of /, '');
        return DEPT_ALIASES[n] || n;
    }
    function findDeptOption(sel, label) {
        var want = normDept(label);
        if (!want) return null;
        var opts = sel.querySelectorAll('option'), i, n, partial = null;
        for (i = 0; i < opts.length; i++) {
            if (opts[i].value === '0') continue;
            n = normDept(opts[i].textContent);
            if (n === want) return opts[i];
            if (!partial && (n.indexOf(want) !== -1 || want.indexOf(n) !== -1)) partial = opts[i];
        }
        return partial;
    }

    var btn = document.getElementById('btn-prefill');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var overwrite = document.getElementById('opt-overwrite').checked;
        var rows = document.querySelectorAll('#bulk-table tbody tr');
        var matched = 0, filled = 0, unmatched = 0, deptMiss = [];

        rows.forEach(function (tr) {
            var r = matchRow(tr.getAttribute('data-emp'), tr.getAttribute('data-name'));
            if (!r) { unmatched++; return; }
            matched++;
            var changed = false;

            var desig = tr.querySelector('.f-desig');
            if (r.desig && (overwrite || !desig.value.trim())) {
                if (desig.value !== r.desig) { desig.value = r.desig; desig.classList.add('bg-warning-subtle'); changed = true; }
            }
            var appt = tr.querySelector('.f-appt');
            if (r.appt && (overwrite || !appt.value)) {
                if (appt.value !== r.appt) { appt.value = r.appt; appt.classList.add('bg-warning-subtle'); changed = true; }
            }
            var dept = tr.querySelector('.f-dept');
            if (r.dept && (overwrite || dept.value === '0')) {
                var opt = findDeptOption(dept, r.dept);
                if (opt) {
                    if (dept.value !== opt.value) { dept.value = opt.value; dept.classList.add('bg-warning-subtle'); changed = true; }
                } else if (deptMiss.indexOf(r.dept) === -1) {
                    deptMiss.push(r.dept);
                }
            }
            if (changed) filled++;
        });

        var box = document.getElementById('prefill-summary');
        var msg = 'Matched ' + matched + ' of ' + rows.length + ' staff against the statement; '
            + filled + ' row(s) prefilled (highlighted). ' + unmatched + ' could not be matched by ID/name.';
        if (deptMiss.length) {
            msg += ' No matching Dept./Section found for: ' + deptMiss.join(', ')
                + ' — add them under Manage Departments and prefill again.';
        }
        msg += ' Review the highlighted values, then press "Save All Changes".';
        box.textContent = msg;
        box.classList.remove('d-none');
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
