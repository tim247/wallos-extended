<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$meterId = intval($data['meter_id'] ?? 0);
$readingDate = validate($data['reading_date'] ?? '');
$readingValue = isset($data['reading_value']) ? floatval($data['reading_value']) : null;
$isMeterChange = !empty($data['is_meter_change']) ? 1 : 0;
$note = validate($data['note'] ?? '');

if ($meterId <= 0 || $readingDate === '' || $readingValue === null || $readingValue < 0) {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

// Verify the meter belongs to the user.
$check = $db->prepare("SELECT id FROM meters WHERE id = :meterId AND user_id = :userId");
$check->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
$check->bindParam(':userId', $userId, SQLITE3_INTEGER);
$checkResult = $check->execute();
if (!$checkResult || !$checkResult->fetchArray()) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

// Reject a reading lower than the previous one (unless meter change).
if (!$isMeterChange) {
    $prev = $db->prepare("SELECT reading_value FROM meter_readings WHERE meter_id = :meterId AND reading_date < :readingDate ORDER BY reading_date DESC LIMIT 1");
    $prev->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
    $prev->bindParam(':readingDate', $readingDate, SQLITE3_TEXT);
    $prevResult = $prev->execute();
    if ($prevRow = $prevResult->fetchArray(SQLITE3_ASSOC)) {
        if ($readingValue < floatval($prevRow['reading_value'])) {
            echo json_encode(['success' => false, 'message' => translate('reading_lower_than_previous', $i18n)]);
            exit();
        }
    }
}

$stmt = $db->prepare("INSERT OR REPLACE INTO meter_readings (meter_id, user_id, reading_date, reading_value, is_meter_change, note)
                      VALUES (:meterId, :userId, :readingDate, :readingValue, :isMeterChange, :note)");
$stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':readingDate', $readingDate, SQLITE3_TEXT);
$stmt->bindParam(':readingValue', $readingValue, SQLITE3_FLOAT);
$stmt->bindParam(':isMeterChange', $isMeterChange, SQLITE3_INTEGER);
$stmt->bindParam(':note', $note, SQLITE3_TEXT);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => translate('reading_added', $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
$db->close();
