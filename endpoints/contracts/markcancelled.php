<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$contractId = intval($data["id"] ?? 0);
$cancelled = !empty($data["cancelled"]) ? 1 : 0;

$query = "UPDATE contracts SET cancellation_status = :cancelled WHERE id = :contractId AND user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindParam(':cancelled', $cancelled, SQLITE3_INTEGER);
$stmt->bindParam(':contractId', $contractId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => translate($cancelled ? 'contract_marked_cancelled' : 'contract_marked_active', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
