<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$contractId = intval($data["id"] ?? 0);
$deleteQuery = "DELETE FROM contracts WHERE id = :contractId AND user_id = :userId";
$deleteStmt = $db->prepare($deleteQuery);
$deleteStmt->bindParam(':contractId', $contractId, SQLITE3_INTEGER);
$deleteStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($deleteStmt->execute()) {
    require_once '../../includes/item_files.php';
    itemFilesDeleteFor($db, (int) $userId, 'contract', $contractId);
    $linkStmt = $db->prepare("DELETE FROM item_documents WHERE item_type = 'contract' AND item_id = :itemId AND user_id = :userId");
    $linkStmt->bindValue(':itemId', $contractId, SQLITE3_INTEGER);
    $linkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $linkStmt->execute();
    echo json_encode([
        "success" => true,
        "message" => translate('contract_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
