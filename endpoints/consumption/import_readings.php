<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$meterId = intval($data['meter_id'] ?? 0);
$readings = $data['readings'] ?? [];

if ($meterId <= 0 || !is_array($readings) || count($readings) === 0) {
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

$imported = 0;
$skipped = 0;

$db->exec('BEGIN TRANSACTION');

$stmt = $db->prepare("INSERT OR REPLACE INTO meter_readings (meter_id, user_id, reading_date, reading_value, is_meter_change, note)
                      VALUES (:meterId, :userId, :readingDate, :readingValue, 0, '')");

foreach ($readings as $reading) {
    $readingDate = $reading['date'] ?? '';
    $readingValue = isset($reading['value']) ? floatval($reading['value']) : null;

    // Expect ISO dates from the client-side mapping step.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $readingDate) || $readingValue === null || $readingValue < 0) {
        $skipped++;
        continue;
    }

    $stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindParam(':readingDate', $readingDate, SQLITE3_TEXT);
    $stmt->bindParam(':readingValue', $readingValue, SQLITE3_FLOAT);

    if ($stmt->execute()) {
        $imported++;
    } else {
        $skipped++;
    }
    $stmt->reset();
}

$db->exec('COMMIT');

echo json_encode([
    'success' => true,
    'imported' => $imported,
    'skipped' => $skipped,
    'message' => sprintf(translate('readings_imported', $i18n), $imported, $skipped),
]);
$db->close();
