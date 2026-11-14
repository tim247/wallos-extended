<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/item_files.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$file = itemFileGet($db, $userId, (int) ($data['id'] ?? 0));
if ($file === null) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
@unlink(itemFilesDir() . '/' . basename($file['stored_name']));
$stmt = $db->prepare('DELETE FROM item_files WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':id', (int) $file['id'], SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->execute();
echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
