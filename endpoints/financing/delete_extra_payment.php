<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$paymentId = intval($data["id"] ?? 0);
$stmt = $db->prepare("DELETE FROM loan_extra_payments WHERE id = :paymentId AND user_id = :userId");
$stmt->bindParam(':paymentId', $paymentId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => translate('extra_payment_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
