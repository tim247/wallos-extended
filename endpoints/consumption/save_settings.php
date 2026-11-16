<?php
// Household data for the consumption comparison and the location for the weather data.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/consumption_insights.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$persons = max(0, min(20, intval($data['persons'] ?? 0)));
$livingArea = max(0.0, min(2000.0, floatval(str_replace(',', '.', (string) ($data['living_area'] ?? 0)))));
$location = trim(mb_substr((string) ($data['location'] ?? ''), 0, 80));

$current = loadConsumptionSettings($db, $userId);
$lat = $current['latitude'];
$lon = $current['longitude'];
$locationName = $current['location_name'];

if ($location === '') {
    $lat = $lon = null;
    $locationName = '';
} elseif ($location !== $locationName) {
    // Postal code or town -> coordinates (Open-Meteo geocoding, no account). Only the search text is sent.
    $json = consumptionHttpGet('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name' => $location, 'count' => 1, 'language' => 'de', 'format' => 'json']));
    $hit = $json ? (json_decode($json, true)['results'][0] ?? null) : null;
    if ($hit === null) {
        echo json_encode(['success' => false, 'message' => translate('location_not_found', $i18n)]);
        exit;
    }
    $lat = round((float) $hit['latitude'], 2);
    $lon = round((float) $hit['longitude'], 2);
    $locationName = trim(($hit['postcodes'][0] ?? '') . ' ' . $hit['name'] . (isset($hit['admin1']) ? ', ' . $hit['admin1'] : ''));
}

$stmt = $db->prepare('INSERT OR REPLACE INTO consumption_settings (user_id, persons, living_area, location_name, latitude, longitude)
    VALUES (:userId, :persons, :area, :name, :lat, :lon)');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':persons', $persons, SQLITE3_INTEGER);
$stmt->bindValue(':area', $livingArea, SQLITE3_FLOAT);
$stmt->bindValue(':name', $locationName);
$stmt->bindValue(':lat', $lat, $lat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
$stmt->bindValue(':lon', $lon, $lon === null ? SQLITE3_NULL : SQLITE3_FLOAT);
$ok = $stmt->execute() !== false;

echo json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n), 'location' => $locationName]);
$db->close();
