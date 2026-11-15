<?php
/*
Read the household finance data. GET, api_key or header X-API-Key.

  type = contracts | insurances | loans | meters | readings | price_history | audit_log
  archived = 1 to include archived entries (contracts, insurances, loans, meters)
  meter_id = only for type=readings

{"success": true, "type": "contracts", "items": [{...all columns...}]}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/api_auth.php';

header('Content-Type: application/json; charset=UTF-8');
$userId = apiAuthenticate($db);

$tables = ['contracts', 'insurances', 'loans', 'meters', 'readings', 'price_history', 'audit_log'];
$type = $_GET['type'] ?? '';
if (!in_array($type, $tables, true)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'title' => 'type must be one of: ' . implode(', ', $tables)]));
}

$archivable = in_array($type, ['contracts', 'insurances', 'loans', 'meters'], true);
$sql = match ($type) {
    'readings' => 'SELECT * FROM meter_readings WHERE user_id = :userId' . (isset($_GET['meter_id']) ? ' AND meter_id = :meterId' : '') . ' ORDER BY meter_id, reading_date',
    'audit_log' => 'SELECT * FROM audit_log WHERE user_id = :userId ORDER BY id DESC LIMIT 500',
    default => "SELECT * FROM $type WHERE user_id = :userId" . ($archivable && empty($_GET['archived']) ? ' AND archived = 0' : '') . ' ORDER BY id',
};
$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
if ($type === 'readings' && isset($_GET['meter_id'])) {
    $stmt->bindValue(':meterId', (int) $_GET['meter_id'], SQLITE3_INTEGER);
}
$result = $stmt->execute();
$items = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    unset($row['user_id']);
    $items[] = $row;
}
echo json_encode(['success' => true, 'type' => $type, 'items' => $items], JSON_UNESCAPED_UNICODE);
