<?php
/**
 * AJAX: look up a pending form sale by form_number, buyer_name, buyer_mobile, or buyer_email.
 * Returns JSON: { ok, buyer_name, buyer_email, buyer_mobile, form_sale_id, form_number }
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('admissions');

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'No form number provided.']);
    exit;
}

$stmt = db()->prepare(
    'SELECT id, form_number, buyer_name, buyer_email, buyer_mobile
     FROM adm_form_sales
     WHERE status = ?
       AND (form_number = ? OR buyer_name LIKE ? OR buyer_mobile LIKE ? OR buyer_email LIKE ?)
     ORDER BY sold_at DESC
     LIMIT 1'
);
$like = '%' . $q . '%';
$stmt->execute(['pending', $q, $like, $like, $like]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No form waiting for admission found for this number.']);
    exit;
}

echo json_encode([
    'ok'           => true,
    'form_sale_id' => (int)$row['id'],
    'form_number'  => $row['form_number'],
    'buyer_name'   => $row['buyer_name'],
    'buyer_email'  => $row['buyer_email'] ?? '',
    'buyer_mobile' => $row['buyer_mobile'],
]);
