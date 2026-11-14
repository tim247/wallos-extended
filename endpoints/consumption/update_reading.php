<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$readingId = intval($data['id'] ?? 0);
$readingDate = validate($data['reading_date'] ?? '');
$readingValue = isset($data['reading_value']) ? floatval($data['reading_value']) : null;
$isMeterChange = !empty($data['is_meter_change']) ? 1 : 0;
$note = validate($data['note'] ?? '');

if ($readingId <= 0 || $readingDate === '' || $readingValue === null || $readingValue < 0) {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

// Verify the reading belongs to the user.
$check = $db->prepare("SELECT id FROM meter_readings WHERE id = :readingId AND user_id = :userId");
$check->bindParam(':readingId', $readingId, SQLITE3_INTEGER);
$check->bindParam(':userId', $userId, SQLITE3_INTEGER);
$checkResult = $check->execute();
if (!$checkResult || !$checkResult->fetchArray()) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

$stmt = $db->prepare("UPDATE meter_readings SET reading_date = :readingDate, reading_value = :readingValue,
                      is_meter_change = :isMeterChange, note = :note
                      WHERE id = :readingId AND user_id = :userId");
$stmt->bindParam(':readingId', $readingId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':readingDate', $readingDate, SQLITE3_TEXT);
$stmt->bindParam(':readingValue', $readingValue, SQLITE3_FLOAT);
$stmt->bindParam(':isMeterChange', $isMeterChange, SQLITE3_INTEGER);
$stmt->bindParam(':note', $note, SQLITE3_TEXT);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => translate('reading_updated', $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
$db->close();
