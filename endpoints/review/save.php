<?php
// Annual review: stores the decision for one contract / insurance in the current year.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$tables = ['contract' => 'contracts', 'insurance' => 'insurances'];
$type = (string) ($data['type'] ?? '');
$id = intval($data['id'] ?? 0);
$decision = (string) ($data['decision'] ?? '');
if (!isset($tables[$type]) || !in_array($decision, ['keep', 'compare', 'cancel'], true)) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$owner = $db->prepare("SELECT COUNT(*) FROM {$tables[$type]} WHERE id = :id AND user_id = :userId");
$owner->bindValue(':id', $id, SQLITE3_INTEGER);
$owner->bindValue(':userId', $userId, SQLITE3_INTEGER);
if (!$owner->execute()->fetchArray()[0]) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$stmt = $db->prepare('INSERT OR REPLACE INTO annual_review (user_id, year, item_type, item_id, decision, decided_at)
    VALUES (:userId, :year, :type, :id, :decision, :today)');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':year', (int) date('Y'), SQLITE3_INTEGER);
$stmt->bindValue(':type', $type);
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':decision', $decision);
$stmt->bindValue(':today', date('Y-m-d'));
echo json_encode(['success' => $stmt->execute() !== false]);
$db->close();
