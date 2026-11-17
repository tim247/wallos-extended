<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$insuranceId = intval($data["id"] ?? 0);
$claimsDelete = $db->prepare('DELETE FROM insurance_claims WHERE insurance_id = :insuranceId AND user_id = :userId');
$claimsDelete->bindValue(':insuranceId', (int) ($data['id'] ?? $_POST['id'] ?? 0), SQLITE3_INTEGER);
$claimsDelete->bindValue(':userId', $userId, SQLITE3_INTEGER);
$claimsDelete->execute();
$freeBlocks = $db->prepare('UPDATE insurances SET parent_id = NULL WHERE parent_id = :insuranceId AND user_id = :userId');
$freeBlocks->bindValue(':insuranceId', $insuranceId, SQLITE3_INTEGER);
$freeBlocks->bindValue(':userId', $userId, SQLITE3_INTEGER);
$freeBlocks->execute();
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
