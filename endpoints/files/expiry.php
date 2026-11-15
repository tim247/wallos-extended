<?php
// Set or clear the expiry date of a document (warranty, guarantee). A reminder follows 30 days ahead.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/item_files.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$date = (string) ($data['expires_at'] ?? '');
$file = itemFileGet($db, (int) $userId, (int) ($data['id'] ?? 0));
if (!$file || ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$stmt = $db->prepare('UPDATE item_files SET expires_at = :date, expiry_notified = NULL WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':date', $date === '' ? null : $date, $date === '' ? SQLITE3_NULL : SQLITE3_TEXT);
$stmt->bindValue(':id', (int) $file['id'], SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
echo json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]);
