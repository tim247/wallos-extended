<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$isEdit = isset($_POST['id']) && $_POST['id'] != "";

$name = validate($_POST['name'] ?? '');
$allowedTypes = ['electricity', 'water', 'gas', 'pv_feed_in', 'pv_generation'];
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
$isHeating = ($meterType === 'electricity' && !empty($_POST['is_heating'])) ? 1 : 0;
$investmentCost = $meterType === 'pv_generation' ? max(0.0, floatval(str_replace(',', '.', $_POST['investment_cost'] ?? '0'))) : 0.0;
$commissioningDate = $meterType === 'pv_generation' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['commissioning_date'] ?? '') ? $_POST['commissioning_date'] : null;
$priceValidFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['price_valid_from'] ?? '') ? $_POST['price_valid_from'] : null;
if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $haEntity)) {
    $haEntity = '';
}

if (!$isEdit) {
    $sql = "INSERT INTO meters (
                user_id, name, meter_type, meter_number, unit, price_per_unit,
                base_fee_monthly, conversion_factor, currency_id,
                monthly_advance, billing_start, reading_reminder_days, ha_entity_id,
                is_heating, investment_cost, commissioning_date, price_valid_from
            ) VALUES (
                :userId, :name, :meterType, :meterNumber, :unit, :pricePerUnit,
                :baseFeeMonthly, :conversionFactor, :currencyId,
                :monthlyAdvance, :billingStart, :reminderDays, :haEntity,
                :isHeating, :investmentCost, :commissioningDate, :priceValidFrom
            )";
} else {
    $id = intval($_POST['id']);
    // Price change: the old price is kept as tariff history, so earlier periods keep their cost.
    $old = $db->prepare('SELECT price_per_unit, base_fee_monthly, price_valid_from FROM meters WHERE id = :id AND user_id = :userId');
    $old->bindValue(':id', $id, SQLITE3_INTEGER);
    $old->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $oldMeter = $old->execute()->fetchArray(SQLITE3_ASSOC);
    if ($oldMeter && (abs((float) $oldMeter['price_per_unit'] - $pricePerUnit) > 0.00001 || abs((float) $oldMeter['base_fee_monthly'] - $baseFeeMonthly) > 0.001)) {
        $keep = $db->prepare('INSERT INTO meter_tariffs (meter_id, user_id, valid_from, price_per_unit, base_fee_monthly) VALUES (:id, :userId, :from, :price, :fee)');
        $keep->bindValue(':id', $id, SQLITE3_INTEGER);
        $keep->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $keep->bindValue(':from', $oldMeter['price_valid_from'] ?: '0000-01-01');
        $keep->bindValue(':price', (float) $oldMeter['price_per_unit'], SQLITE3_FLOAT);
        $keep->bindValue(':fee', (float) $oldMeter['base_fee_monthly'], SQLITE3_FLOAT);
        $keep->execute();
        if ($priceValidFrom === null || $priceValidFrom === $oldMeter['price_valid_from']) {
            $priceValidFrom = date('Y-m-d');
        }
    } elseif ($oldMeter && $priceValidFrom === null) {
        $priceValidFrom = $oldMeter['price_valid_from'];
    }
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
                ha_entity_id = :haEntity,
                is_heating = :isHeating,
                investment_cost = :investmentCost,
                commissioning_date = :commissioningDate,
                price_valid_from = :priceValidFrom
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
$stmt->bindValue(':isHeating', $isHeating, SQLITE3_INTEGER);
$stmt->bindValue(':investmentCost', $investmentCost, SQLITE3_FLOAT);
$stmt->bindValue(':commissioningDate', $commissioningDate, $commissioningDate === null ? SQLITE3_NULL : SQLITE3_TEXT);
$stmt->bindValue(':priceValidFrom', $priceValidFrom, $priceValidFrom === null ? SQLITE3_NULL : SQLITE3_TEXT);
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
