<?php
// Earlier prices of a meter: add or delete (the current price lives on the meter itself).
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];

if (($data['action'] ?? 'add') === 'delete') {
    $stmt = $db->prepare('DELETE FROM meter_tariffs WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} else {
    $meterId = intval($data['meter_id'] ?? 0);
    $owner = $db->prepare('SELECT COUNT(*) FROM meters WHERE id = :id AND user_id = :userId');
    $owner->bindValue(':id', $meterId, SQLITE3_INTEGER);
    $owner->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if (!$owner->execute()->fetchArray()[0] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['valid_from'] ?? ''))) {
        echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
        exit;
    }
    $stmt = $db->prepare('INSERT INTO meter_tariffs (meter_id, user_id, valid_from, price_per_unit, base_fee_monthly)
        VALUES (:meterId, :userId, :from, :price, :fee)');
    $stmt->bindValue(':meterId', $meterId, SQLITE3_INTEGER);
    $stmt->bindValue(':from', $data['valid_from']);
    $stmt->bindValue(':price', max(0.0, floatval(str_replace(',', '.', (string) ($data['price_per_unit'] ?? 0)))), SQLITE3_FLOAT);
    $stmt->bindValue(':fee', max(0.0, floatval(str_replace(',', '.', (string) ($data['base_fee_monthly'] ?? 0)))), SQLITE3_FLOAT);
}
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute() !== false;
echo json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n)]);
$db->close();
