<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$contractId = intval($data["id"] ?? 0);
$archived = !empty($data["archived"]) ? 1 : 0;
$archivedAt = $archived ? date('Y-m-d') : null;

$query = "UPDATE contracts SET archived = :archived, archived_at = :archivedAt WHERE id = :contractId AND user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindParam(':archived', $archived, SQLITE3_INTEGER);
$stmt->bindParam(':archivedAt', $archivedAt, SQLITE3_TEXT);
$stmt->bindParam(':contractId', $contractId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => translate($archived ? 'item_archived' : 'item_unarchived', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
