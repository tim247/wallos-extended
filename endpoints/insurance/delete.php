<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$insuranceId = intval($data["id"] ?? 0);
$deleteQuery = "DELETE FROM insurances WHERE id = :insuranceId AND user_id = :userId";
$deleteStmt = $db->prepare($deleteQuery);
$deleteStmt->bindParam(':insuranceId', $insuranceId, SQLITE3_INTEGER);
$deleteStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($deleteStmt->execute()) {
    require_once '../../includes/item_files.php';
    itemFilesDeleteFor($db, (int) $userId, 'insurance', $insuranceId);
    $linkStmt = $db->prepare("DELETE FROM item_documents WHERE item_type = 'insurance' AND item_id = :itemId AND user_id = :userId");
    $linkStmt->bindValue(':itemId', $insuranceId, SQLITE3_INTEGER);
    $linkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $linkStmt->execute();
    echo json_encode([
        "success" => true,
        "message" => translate('insurance_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
