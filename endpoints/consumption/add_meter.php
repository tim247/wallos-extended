<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$isEdit = isset($_POST['id']) && $_POST['id'] != "";

$name = validate($_POST['name'] ?? '');
$allowedTypes = ['electricity', 'water', 'gas', 'pv_feed_in'];
$meterType = validate($_POST['meter_type'] ?? 'electricity');
if (!in_array($meterType, $allowedTypes)) {
    $meterType = 'electricity';
}

if ($name === '') {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

$meterNumber = validate($_POST['meter_number'] ?? '');
$unit = ($meterType === 'water' || $meterType === 'gas') ? 'm3' : 'kWh';
$pricePerUnit = floatval(str_replace(',', '.', $_POST['price_per_unit'] ?? '0'));
$baseFeeMonthly = floatval(str_replace(',', '.', $_POST['base_fee_monthly'] ?? '0'));
$conversionFactor = ($_POST['conversion_factor'] ?? '') !== ''
    ? floatval(str_replace(',', '.', $_POST['conversion_factor']))
    : null;
$currencyId = intval($_POST['currency_id'] ?? 1);
$monthlyAdvance = floatval(str_replace(',', '.', $_POST['monthly_advance'] ?? '0'));
$billingStart = validate($_POST['billing_start'] ?? '');
if ($billingStart !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $billingStart)) {
    $billingStart = '';
}
$reminderDays = max(0, intval($_POST['reading_reminder_days'] ?? 0));
$haEntity = strtolower(trim((string) ($_POST['ha_entity_id'] ?? '')));
if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $haEntity)) {
    $haEntity = '';
}

if (!$isEdit) {
    $sql = "INSERT INTO meters (
                user_id, name, meter_type, meter_number, unit, price_per_unit,
                base_fee_monthly, conversion_factor, currency_id,
                monthly_advance, billing_start, reading_reminder_days, ha_entity_id
            ) VALUES (
                :userId, :name, :meterType, :meterNumber, :unit, :pricePerUnit,
                :baseFeeMonthly, :conversionFactor, :currencyId,
                :monthlyAdvance, :billingStart, :reminderDays, :haEntity
            )";
} else {
    $id = intval($_POST['id']);
    $sql = "UPDATE meters SET
                name = :name,
                meter_type = :meterType,
                meter_number = :meterNumber,
                unit = :unit,
                price_per_unit = :pricePerUnit,
                base_fee_monthly = :baseFeeMonthly,
                conversion_factor = :conversionFactor,
                currency_id = :currencyId,
                monthly_advance = :monthlyAdvance,
                billing_start = :billingStart,
                reading_reminder_days = :reminderDays,
                ha_entity_id = :haEntity
            WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
$stmt->bindParam(':meterType', $meterType, SQLITE3_TEXT);
$stmt->bindParam(':meterNumber', $meterNumber, SQLITE3_TEXT);
$stmt->bindParam(':unit', $unit, SQLITE3_TEXT);
$stmt->bindParam(':pricePerUnit', $pricePerUnit, SQLITE3_FLOAT);
$stmt->bindParam(':baseFeeMonthly', $baseFeeMonthly, SQLITE3_FLOAT);
$stmt->bindParam(':conversionFactor', $conversionFactor, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':monthlyAdvance', $monthlyAdvance, SQLITE3_FLOAT);
$stmt->bindParam(':billingStart', $billingStart, SQLITE3_TEXT);
$stmt->bindParam(':reminderDays', $reminderDays, SQLITE3_INTEGER);
$stmt->bindParam(':haEntity', $haEntity, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}

header('Content-Type: application/json');
if ($stmt->execute()) {
    $text = $isEdit ? 'meter_updated_successfully' : 'meter_added_successfully';
    echo json_encode(['success' => true, 'status' => 'Success', 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
?>
