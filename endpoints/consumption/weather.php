<?php
// Weather-adjusted analysis of a heating meter: degree days per month from the daily
// mean temperatures (Open-Meteo, cached) against the monthly consumption.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/consumption_insights.php';
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['error' => 'session_expired']);
    exit;
}

$stmt = $db->prepare('SELECT * FROM meters WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':id', intval($_GET['id'] ?? 0), SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$meter = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$settings = loadConsumptionSettings($db, $userId);
if (!$meter || $settings['latitude'] === null) {
    echo json_encode(['error' => translate('weather_needs_location', $i18n)]);
    exit;
}

$readings = loadMeterRows($db, 'meter_readings', (int) $meter['id'], $userId, 'reading_date');
$segments = computeConsumptionSegments($readings)['segments'];
if (!$segments) {
    echo json_encode(['error' => translate('not_enough_data_forecast', $i18n)]);
    exit;
}
$monthly = computeMonthlyBuckets($segments);
$from = array_key_first($monthly) . '-01';
$days = loadDailyTemperatures($db, (float) $settings['latitude'], (float) $settings['longitude'], $from, date('Y-m-d'));
if (!$days) {
    echo json_encode(['error' => translate('weather_unavailable', $i18n)]);
    exit;
}
$hdd = heatingDegreeDays($days);
// A month counts only when its weather is complete (the current month usually is not).
$perMonth = [];
foreach (array_keys($days) as $day) {
    $perMonth[substr($day, 0, 7)] = ($perMonth[substr($day, 0, 7)] ?? 0) + 1;
}
foreach ($hdd as $month => $value) {
    if (($perMonth[$month] ?? 0) < (int) date('t', strtotime($month . '-01'))) {
        unset($hdd[$month]);
    }
}
$conversion = meterConversion($meter);
$unit = ($meter['meter_type'] === 'gas' && $conversion <= 1) ? 'm³' : 'kWh';
$monthlyConverted = array_map(fn($v) => round($v * $conversion, 2), $monthly);
echo json_encode([
    'success' => true,
    'unit' => $unit,
    'location' => $settings['location_name'],
    'months' => array_values(array_intersect(array_keys($monthlyConverted), array_keys($hdd))),
    'consumption' => $monthlyConverted,
    'degree_days' => $hdd,
    'years' => weatherAdjustedYears($monthlyConverted, $hdd),
]);
$db->close();
