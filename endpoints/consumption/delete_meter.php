<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$meterId = intval($data["id"] ?? 0);
$deleteStmt = $db->prepare("DELETE FROM meters WHERE id = :meterId AND user_id = :userId");
$deleteStmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
$deleteStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($deleteStmt->execute()) {
    $stmt = $db->prepare("DELETE FROM meter_readings WHERE meter_id = :meterId AND user_id = :userId");
    $stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "message" => translate('meter_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
