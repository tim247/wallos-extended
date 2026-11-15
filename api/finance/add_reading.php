<?php
/*
Add a meter reading, e.g. from Home Assistant or n8n. POST, api_key or header X-API-Key.

  meter_id (required), reading_value (required), reading_date (Y-m-d, default today), note

Replaces an existing reading of the same day. Rejects values lower than the previous reading.
{"success": true}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/api_auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'title' => 'POST only']));
}
$userId = apiAuthenticate($db);

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$meterId = (int) ($input['meter_id'] ?? 0);
$value = isset($input['reading_value']) && is_numeric($input['reading_value']) ? (float) $input['reading_value'] : null;
$date = (string) ($input['reading_date'] ?? date('Y-m-d'));
$note = mb_substr(strip_tags((string) ($input['note'] ?? '')), 0, 200);
if ($meterId <= 0 || $value === null || $value < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'title' => 'meter_id, reading_value and a reading_date as Y-m-d are required']));
}

$stmt = $db->prepare('SELECT id FROM meters WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':id', $meterId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
if (!$stmt->execute()->fetchArray()) {
    http_response_code(404);
    die(json_encode(['success' => false, 'title' => 'Meter not found']));
}

$stmt = $db->prepare('SELECT reading_value FROM meter_readings WHERE meter_id = :id AND reading_date < :date ORDER BY reading_date DESC LIMIT 1');
$stmt->bindValue(':id', $meterId, SQLITE3_INTEGER);
$stmt->bindValue(':date', $date);
$previous = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if ($previous && $value < (float) $previous['reading_value']) {
    http_response_code(422);
    die(json_encode(['success' => false, 'title' => 'Reading is lower than the previous one']));
}

$stmt = $db->prepare('INSERT INTO meter_readings (meter_id, user_id, reading_date, reading_value, note) VALUES (:id, :userId, :date, :value, :note)
    ON CONFLICT(meter_id, reading_date) DO UPDATE SET reading_value = excluded.reading_value, note = excluded.note');
$stmt->bindValue(':id', $meterId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':date', $date);
$stmt->bindValue(':value', $value, SQLITE3_FLOAT);
$stmt->bindValue(':note', $note);
echo json_encode(['success' => (bool) $stmt->execute()]);
