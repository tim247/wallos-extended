<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$readingId = intval($data["id"] ?? 0);
$stmt = $db->prepare("DELETE FROM meter_readings WHERE id = :readingId AND user_id = :userId");
$stmt->bindParam(':readingId', $readingId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => translate('reading_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
