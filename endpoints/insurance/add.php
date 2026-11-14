<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';

$isEdit = isset($_POST['id']) && $_POST['id'] != "";

$name = validate($_POST['name'] ?? '');
if ($name === '') {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

$allowedTypes = ['liability', 'household', 'car', 'life', 'disability', 'legal', 'health', 'building', 'pet', 'travel', 'accident', 'other'];

$insurer = validate($_POST['insurer'] ?? '');
$policyNumber = validate($_POST['policy_number'] ?? '');
$insuranceType = validate($_POST['insurance_type'] ?? 'other');
if (!in_array($insuranceType, $allowedTypes)) {
    $insuranceType = 'other';
}
$price = floatval($_POST['price'] ?? 0);
$currencyId = intval($_POST['currency_id'] ?? 1);
$cycle = intval($_POST['cycle'] ?? 3);
$frequency = intval($_POST['frequency'] ?? 1);
$paymentMethodId = intval($_POST['payment_method_id'] ?? 1);
$payerUserId = intval($_POST['payer_user_id'] ?? 1);
$coverageAmount = ($_POST['coverage_amount'] ?? '') !== '' ? floatval($_POST['coverage_amount']) : null;
$deductible = ($_POST['deductible'] ?? '') !== '' ? floatval($_POST['deductible']) : null;
$startDate = validate($_POST['start_date'] ?? '');
$endDate = validate($_POST['end_date'] ?? '');
$endDate = $endDate !== '' ? $endDate : null;
$autoRenews = isset($_POST['auto_renews']) ? 1 : 0;
$renewalTermMonths = intval($_POST['renewal_term_months'] ?? 12);
$noticePeriodMonths = intval($_POST['notice_period_months'] ?? 3);
$noticePeriodDays = intval($_POST['notice_period_days'] ?? 0);
$notify = isset($_POST['notifications']) ? 1 : 0;
$notifyDaysBefore = intval($_POST['notify_days_before'] ?? 30);
$cancellationStatus = isset($_POST['cancellation_status']) ? 1 : 0;
$notes = validate($_POST['notes'] ?? '');
$url = validate($_POST['url'] ?? '');

if (!$isEdit) {
    $sql = "INSERT INTO insurances (
                user_id, name, insurer, policy_number, insurance_type, price, currency_id,
                cycle, frequency, payment_method_id, payer_user_id, coverage_amount, deductible,
                start_date, end_date, auto_renews, renewal_term_months,
                notice_period_months, notice_period_days, notify, notify_days_before,
                cancellation_status, notes, url
            ) VALUES (
                :userId, :name, :insurer, :policyNumber, :insuranceType, :price, :currencyId,
                :cycle, :frequency, :paymentMethodId, :payerUserId, :coverageAmount, :deductible,
                :startDate, :endDate, :autoRenews, :renewalTermMonths,
                :noticePeriodMonths, :noticePeriodDays, :notify, :notifyDaysBefore,
                :cancellationStatus, :notes, :url
            )";
} else {
    $id = intval($_POST['id']);
    $sql = "UPDATE insurances SET
                name = :name,
                insurer = :insurer,
                policy_number = :policyNumber,
                insurance_type = :insuranceType,
                price = :price,
                currency_id = :currencyId,
                cycle = :cycle,
                frequency = :frequency,
                payment_method_id = :paymentMethodId,
                payer_user_id = :payerUserId,
                coverage_amount = :coverageAmount,
                deductible = :deductible,
                start_date = :startDate,
                end_date = :endDate,
                auto_renews = :autoRenews,
                renewal_term_months = :renewalTermMonths,
                notice_period_months = :noticePeriodMonths,
                notice_period_days = :noticePeriodDays,
                notify = :notify,
                notify_days_before = :notifyDaysBefore,
                cancellation_status = :cancellationStatus,
                cancellation_sent_date = CASE WHEN :cancellationStatus = 1 THEN cancellation_sent_date ELSE NULL END,
                cancellation_confirmed_date = CASE WHEN :cancellationStatus = 1 THEN cancellation_confirmed_date ELSE NULL END,
                notes = :notes,
                url = :url,
                last_notified_deadline = NULL
            WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
$stmt->bindParam(':insurer', $insurer, SQLITE3_TEXT);
$stmt->bindParam(':policyNumber', $policyNumber, SQLITE3_TEXT);
$stmt->bindParam(':insuranceType', $insuranceType, SQLITE3_TEXT);
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':coverageAmount', $coverageAmount, SQLITE3_FLOAT);
$stmt->bindParam(':deductible', $deductible, SQLITE3_FLOAT);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':endDate', $endDate, SQLITE3_TEXT);
$stmt->bindParam(':autoRenews', $autoRenews, SQLITE3_INTEGER);
$stmt->bindParam(':renewalTermMonths', $renewalTermMonths, SQLITE3_INTEGER);
$stmt->bindParam(':noticePeriodMonths', $noticePeriodMonths, SQLITE3_INTEGER);
$stmt->bindParam(':noticePeriodDays', $noticePeriodDays, SQLITE3_INTEGER);
$stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
$stmt->bindParam(':cancellationStatus', $cancellationStatus, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':url', $url, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}

header('Content-Type: application/json');
if ($stmt->execute()) {
    $text = $isEdit ? 'insurance_updated_successfully' : 'insurance_added_successfully';
    echo json_encode(['success' => true, 'status' => 'Success', 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
?>
