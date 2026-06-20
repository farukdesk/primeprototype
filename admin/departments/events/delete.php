<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-events', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    flash_set('error', 'Event not found.');
    redirect(APP_URL . '/departments/events/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$event['dept_id'];
require_access_dept($dept_id);

db()->prepare('DELETE FROM dept_events WHERE id = ?')->execute([$id]);
flash_set('success', 'Event deleted.');
redirect(APP_URL . '/departments/events/index.php?dept_id=' . ($dept_id ?: $event['dept_id']));
