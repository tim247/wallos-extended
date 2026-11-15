<?php
// Home Assistant integration: reads a cumulative sensor (kWh, state_class
// total_increasing) and stores it as a meter reading. Read-only.

// Same network rules as Paperless (private addresses: admin only).
require_once __DIR__ . '/paperless.php';

const HA_READING_NOTE = 'Home Assistant';

function homeAssistantConfig(SQLite3 $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT url, token FROM homeassistant_settings WHERE user_id = :userId AND enabled = 1");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return ($row && $row['url'] !== '' && $row['token'] !== '') ? $row : null;
}

function homeAssistantValidEntity(string $entityId): bool
{
    return (bool) preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $entityId);
}

/** GET against the Home Assistant API; returns ['data' => array] or ['error' => i18n key suffix]. */
function homeAssistantGet(array $cfg, int $userId, string $path): array
{
    $response = paperlessRequest($cfg, $userId, $path, [], 'Bearer');
    if ($response['error'] !== '') {
        return ['error' => $response['error']];
    }
    if ($response['status'] === 401 || $response['status'] === 403) {
        return ['error' => 'auth'];
    }
    if ($response['status'] === 404) {
        return ['error' => 'entity_not_found'];
    }
    $data = json_decode($response['body'], true);
    return ($response['status'] === 200 && is_array($data)) ? ['data' => $data] : ['error' => 'unexpected'];
}

/**
 * Current value of a sensor, converted to the meter unit.
 * Energy sensors may report Wh / kWh / MWh; everything else is taken as is (e.g. m³).
 * @return array{value?: float, unit?: string, error?: string}
 */
function homeAssistantReadSensor(array $cfg, int $userId, string $entityId): array
{
    if (!homeAssistantValidEntity($entityId)) {
        return ['error' => 'invalid_entity'];
    }
    $result = homeAssistantGet($cfg, $userId, '/api/states/' . $entityId);
    if (isset($result['error'])) {
        return $result;
    }
    $state = $result['data']['state'] ?? null;
    if (!is_numeric($state)) {
        return ['error' => 'state_unavailable']; // "unavailable" / "unknown"
    }
    $unit = (string) ($result['data']['attributes']['unit_of_measurement'] ?? '');
    $factor = ['Wh' => 0.001, 'kWh' => 1.0, 'MWh' => 1000.0, 'GWh' => 1000000.0][$unit] ?? 1.0;
    return ['value' => round((float) $state * $factor, 3), 'unit' => $unit];
}

/**
 * Stores today's reading of a meter from its Home Assistant sensor.
 * A value below the previous reading (sensor reset / device replaced) is saved
 * as a meter change. A manual reading of the same day is never overwritten.
 * @return array{status: string, value?: float, error?: string}
 */
function homeAssistantImportReading(SQLite3 $db, int $userId, array $meter, array $cfg): array
{
    $sensor = homeAssistantReadSensor($cfg, $userId, (string) $meter['ha_entity_id']);
    if (isset($sensor['error'])) {
        return ['status' => 'error', 'error' => $sensor['error']];
    }
    $today = date('Y-m-d');

    $stmt = $db->prepare("SELECT note FROM meter_readings WHERE meter_id = :meterId AND reading_date = :today");
    $stmt->bindValue(':meterId', $meter['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing && strpos((string) $existing['note'], HA_READING_NOTE) !== 0) {
        return ['status' => 'skipped', 'value' => $sensor['value']];
    }

    $stmt = $db->prepare("SELECT reading_value FROM meter_readings WHERE meter_id = :meterId AND reading_date < :today ORDER BY reading_date DESC LIMIT 1");
    $stmt->bindValue(':meterId', $meter['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $previous = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $isChange = $previous && $sensor['value'] < (float) $previous['reading_value'] ? 1 : 0;

    $stmt = $db->prepare("INSERT OR REPLACE INTO meter_readings (meter_id, user_id, reading_date, reading_value, is_meter_change, note)
                          VALUES (:meterId, :userId, :today, :value, :isChange, :note)");
    $stmt->bindValue(':meterId', $meter['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $stmt->bindValue(':value', $sensor['value'], SQLITE3_FLOAT);
    $stmt->bindValue(':isChange', $isChange, SQLITE3_INTEGER);
    $stmt->bindValue(':note', HA_READING_NOTE . ($isChange ? ' (reset)' : ''), SQLITE3_TEXT);
    return $stmt->execute()
        ? ['status' => 'saved', 'value' => $sensor['value'], 'meter_change' => (bool) $isChange]
        : ['status' => 'error', 'error' => 'unexpected'];
}

/** Notify service of a user ("mobile_app_pixel_7" for notify.mobile_app_pixel_7), or null. */
function homeAssistantNotifyService(SQLite3 $db, int $userId): ?string
{
    $stmt = @$db->prepare("SELECT notify_service FROM homeassistant_settings WHERE user_id = :userId AND enabled = 1");
    if (!$stmt) {
        return null; // before the migration
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $service = (string) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['notify_service'] ?? '');
    return preg_match('/^[a-z0-9_]+$/', $service) ? $service : null;
}

/** Sends a message through a Home Assistant notify service; returns '' or an error key suffix. */
function homeAssistantNotify(array $cfg, int $userId, string $service, string $title, string $message): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $service)) {
        return 'invalid_entity';
    }
    $response = paperlessRequest($cfg, $userId, '/api/services/notify/' . $service, [], 'Bearer',
        ['title' => mb_substr($title, 0, 200), 'message' => mb_substr($message, 0, 4000)]);
    if ($response['error'] !== '') {
        return $response['error'];
    }
    if ($response['status'] === 401 || $response['status'] === 403) {
        return 'auth';
    }
    return $response['status'] >= 200 && $response['status'] < 300 ? '' : ($response['status'] === 400 || $response['status'] === 404 ? 'notify_not_found' : 'unexpected');
}
