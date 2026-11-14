<?php
// "Fetch now": stores today's reading of one meter from its Home Assistant sensor.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/homeassistant.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$stmt = $db->prepare("SELECT * FROM meters WHERE id = :id AND user_id = :userId AND ha_entity_id != ''");
$stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$meter = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$cfg = homeAssistantConfig($db, $userId);
if (!$meter || $cfg === null) {
    echo json_encode(['success' => false, 'message' => translate('ha_error_not_configured', $i18n)]);
    exit;
}

$result = homeAssistantImportReading($db, $userId, $meter, $cfg);
if ($result['status'] === 'error') {
    echo json_encode(['success' => false, 'message' => translate('ha_error_' . $result['error'], $i18n)]);
} elseif ($result['status'] === 'skipped') {
    echo json_encode(['success' => false, 'message' => translate('ha_reading_skipped', $i18n)]);
} else {
    echo json_encode(['success' => true, 'message' => sprintf(translate('ha_reading_saved', $i18n), number_format($result['value'], 3, ',', '.'))]);
}
$db->close();
