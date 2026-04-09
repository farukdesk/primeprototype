<?php
/**
 * AJAX: look up a pending form sale by form_number.
 * Returns JSON: { ok, buyer_name, buyer_email, buyer_mobile, form_sale_id }
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
    'SELECT id, buyer_name, buyer_email, buyer_mobile
     FROM adm_form_sales
     WHERE form_number = ? AND status = ?'
);
$stmt->execute([$q, 'pending']);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No form waiting for admission found for this number.']);
    exit;
}

echo json_encode([
    'ok'           => true,
    'form_sale_id' => (int)$row['id'],
    'buyer_name'   => $row['buyer_name'],
    'buyer_email'  => $row['buyer_email'] ?? '',
    'buyer_mobile' => $row['buyer_mobile'],
]);
