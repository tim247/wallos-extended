<?php
// Supplier bills of a meter: add or delete.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);

if (($data['action'] ?? 'add') === 'delete') {
    $stmt = $db->prepare('DELETE FROM meter_bills WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} else {
    $meterId = intval($data['meter_id'] ?? 0);
    $owner = $db->prepare('SELECT COUNT(*) FROM meters WHERE id = :id AND user_id = :userId');
    $owner->bindValue(':id', $meterId, SQLITE3_INTEGER);
    $owner->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if (!$owner->execute()->fetchArray()[0] || !$isDate($data['period_from'] ?? null) || !$isDate($data['period_to'] ?? null)
        || $data['period_to'] < $data['period_from']) {
        echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
        exit;
    }
    $stmt = $db->prepare('INSERT INTO meter_bills (meter_id, user_id, period_from, period_to, units, amount, note)
        VALUES (:meterId, :userId, :from, :to, :units, :amount, :note)');
    $stmt->bindValue(':meterId', $meterId, SQLITE3_INTEGER);
    $stmt->bindValue(':from', $data['period_from']);
    $stmt->bindValue(':to', $data['period_to']);
    $stmt->bindValue(':units', max(0.0, floatval(str_replace(',', '.', (string) ($data['units'] ?? 0)))), SQLITE3_FLOAT);
    $stmt->bindValue(':amount', floatval(str_replace(',', '.', (string) ($data['amount'] ?? 0))), SQLITE3_FLOAT);
    $stmt->bindValue(':note', validate(mb_substr((string) ($data['note'] ?? ''), 0, 200)));
}
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute() !== false;
echo json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n)]);
$db->close();
