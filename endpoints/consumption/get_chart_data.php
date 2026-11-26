<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/consumption_calculations.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'session_expired']);
    exit();
}

$meterId = intval($_GET['id'] ?? 0);
$targetDate = $_GET['target_date'] ?? date('Y') . '-12-31';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    $targetDate = date('Y') . '-12-31';
}

$stmt = $db->prepare("SELECT * FROM meters WHERE id = :meterId AND user_id = :userId");
$stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$meter = $result->fetchArray(SQLITE3_ASSOC);

if (!$meter) {
    header('Content-Type: application/json');
    echo json_encode(['error' => translate('error', $i18n)]);
    exit();
}

$stmt = $db->prepare("SELECT reading_date, reading_value, is_meter_change FROM meter_readings WHERE meter_id = :meterId AND user_id = :userId ORDER BY reading_date ASC");
$stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$readings = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $readings[] = $row;
}

$computed = computeConsumptionSegments($readings);
$buckets = computeMonthlyBuckets($computed['segments']);
require_once '../../includes/consumption_insights.php';
$forecast = meterForecast($db, (int) $userId, $meter, $computed['segments'], $targetDate);

$forecastCost = null;
if ($forecast !== null) {
    $months = max(0, (strtotime($targetDate) - strtotime($forecast['from'])) / 86400 / 30.44);
    $units = $forecast['projected_units'];
    if ($meter['meter_type'] === 'gas' && !empty($meter['conversion_factor'])) {
        $units = $units * floatval($meter['conversion_factor']);
    }
    if ($meter['meter_type'] === 'pv_feed_in') {
        $forecastCost = round($units * floatval($meter['price_per_unit']), 2);
    } else {
        $forecastCost = round($units * floatval($meter['price_per_unit']) + floatval($meter['base_fee_monthly']) * $months, 2);
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'meter_id' => $meterId,
    'unit' => $meter['unit'],
    // Gas: m³ to kWh, so the forecast can show both.
    'conversion_factor' => $meter['meter_type'] === 'gas' && !empty($meter['conversion_factor']) ? (float) $meter['conversion_factor'] : null,
    'monthly' => $buckets,
    'forecast' => $forecast,
    'forecast_cost' => $forecastCost,
    'warnings' => $computed['warnings'],
]);
$db->close();
