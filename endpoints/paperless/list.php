<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$itemId = intval($data['item_id'] ?? 0);
$cfg = paperlessConfig($db, $userId);
if ($cfg === null || paperlessItem($db, $userId, $type, $itemId) === null) {
    paperlessError('paperless_not_configured', $i18n);
}

$stmt = $db->prepare("SELECT paperless_id AS id, title, doc_date AS date, correspondent FROM item_documents WHERE user_id = :userId AND item_type = :type AND item_id = :itemId ORDER BY doc_date DESC, id DESC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $type, SQLITE3_TEXT);
$stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
$result = $stmt->execute();
$documents = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['id'] = (int) $row['id'];
    $documents[] = $row;
}
echo json_encode(['success' => true, 'documents' => $documents, 'base_url' => rtrim($cfg['url'], '/')]);
$db->close();
