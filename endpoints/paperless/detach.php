<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$stmt = $db->prepare("DELETE FROM item_documents WHERE user_id = :userId AND item_type = :type AND item_id = :itemId AND paperless_id = :paperlessId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', (string) ($data['type'] ?? ''), SQLITE3_TEXT);
$stmt->bindValue(':itemId', intval($data['item_id'] ?? 0), SQLITE3_INTEGER);
$stmt->bindValue(':paperlessId', intval($data['paperless_id'] ?? 0), SQLITE3_INTEGER);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('paperless_detached', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
