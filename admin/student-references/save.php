<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-references', 'can_create');

csrf_check();

$valid_types = ['batches', 'exams', 'boards', 'groups'];
$type = $_POST['type'] ?? '';

if (!in_array($type, $valid_types, true)) {
    flash_set('error', 'Invalid reference type.');
    redirect(APP_URL . '/student-references/index.php');
}

$table_map = [
    'batches' => 'student_batches',
    'exams'   => 'student_exam_titles',
    'boards'  => 'student_boards',
    'groups'  => 'student_groups',
];
$has_short = in_array($type, ['exams', 'boards'], true);

$table      = $table_map[$type];
$id         = (int)($_POST['id']   ?? 0);
$name       = trim($_POST['name']  ?? '');
$short_name = $has_short ? trim($_POST['short_name'] ?? '') : null;
$sort_order = (int)($_POST['sort_order'] ?? 0);

if ($name === '') {
    flash_set('error', 'Name is required.');
    redirect(APP_URL . '/student-references/index.php?tab=' . urlencode($type));
}

if ($id > 0) {
    // Update
    if ($has_short) {
        db()->prepare("UPDATE `$table` SET name = ?, short_name = ?, sort_order = ? WHERE id = ?")
            ->execute([$name, $short_name ?: null, $sort_order, $id]);
    } else {
        db()->prepare("UPDATE `$table` SET name = ?, sort_order = ? WHERE id = ?")
            ->execute([$name, $sort_order, $id]);
    }
    flash_set('success', 'Record updated successfully.');
} else {
    // Insert
    if ($has_short) {
        db()->prepare("INSERT INTO `$table` (name, short_name, sort_order) VALUES (?, ?, ?)")
            ->execute([$name, $short_name ?: null, $sort_order]);
    } else {
        db()->prepare("INSERT INTO `$table` (name, sort_order) VALUES (?, ?)")
            ->execute([$name, $sort_order]);
    }
    flash_set('success', 'Record added successfully.');
}

redirect(APP_URL . '/student-references/index.php?tab=' . urlencode($type));
