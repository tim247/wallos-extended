<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/item_files.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$itemId = (int) ($data['item_id'] ?? 0);
if (!itemFileOwns($db, $userId, $type, $itemId)) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$stmt = $db->prepare('SELECT id, filename, size, uploaded_at FROM item_files WHERE user_id = :userId AND item_type = :type AND item_id = :itemId ORDER BY uploaded_at DESC');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $type);
$stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
$result = $stmt->execute();
$files = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $files[] = $row;
}
echo json_encode(['success' => true, 'files' => $files]);
