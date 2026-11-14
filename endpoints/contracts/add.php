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

$provider = validate($_POST['provider'] ?? '');
$contractNumber = validate($_POST['contract_number'] ?? '');
$price = floatval($_POST['price'] ?? 0);
$currencyId = intval($_POST['currency_id'] ?? 1);
$cycle = intval($_POST['cycle'] ?? 3);
$frequency = intval($_POST['frequency'] ?? 1);
$paymentMethodId = intval($_POST['payment_method_id'] ?? 1);
$payerUserId = intval($_POST['payer_user_id'] ?? 1);
$categoryId = intval($_POST['category_id'] ?? 1);
$startDate = validate($_POST['start_date'] ?? '');
$initialTermMonths = intval($_POST['initial_term_months'] ?? 12);
$renewalTermMonths = intval($_POST['renewal_term_months'] ?? 12);
$autoRenews = isset($_POST['auto_renews']) ? 1 : 0;
$endDate = validate($_POST['end_date'] ?? '');
$endDate = $endDate !== '' ? $endDate : null;
$noticePeriodMonths = intval($_POST['notice_period_months'] ?? 3);
$noticePeriodDays = intval($_POST['notice_period_days'] ?? 0);
$notify = isset($_POST['notifications']) ? 1 : 0;
$notifyDaysBefore = intval($_POST['notify_days_before'] ?? 30);
$cancellationStatus = isset($_POST['cancellation_status']) ? 1 : 0;
$notes = validate($_POST['notes'] ?? '');
$url = validate($_POST['url'] ?? '');

if (!$isEdit) {
    $sql = "INSERT INTO contracts (
                user_id, name, category_id, provider, contract_number, price, currency_id,
                cycle, frequency, payment_method_id, payer_user_id, start_date,
                initial_term_months, renewal_term_months, auto_renews, end_date,
                notice_period_months, notice_period_days, notify, notify_days_before,
                cancellation_status, notes, url
            ) VALUES (
                :userId, :name, :categoryId, :provider, :contractNumber, :price, :currencyId,
                :cycle, :frequency, :paymentMethodId, :payerUserId, :startDate,
                :initialTermMonths, :renewalTermMonths, :autoRenews, :endDate,
                :noticePeriodMonths, :noticePeriodDays, :notify, :notifyDaysBefore,
                :cancellationStatus, :notes, :url
            )";
} else {
    $id = intval($_POST['id']);
    $sql = "UPDATE contracts SET
                name = :name,
                category_id = :categoryId,
                provider = :provider,
                contract_number = :contractNumber,
                price = :price,
                currency_id = :currencyId,
                cycle = :cycle,
                frequency = :frequency,
                payment_method_id = :paymentMethodId,
                payer_user_id = :payerUserId,
                start_date = :startDate,
                initial_term_months = :initialTermMonths,
                renewal_term_months = :renewalTermMonths,
                auto_renews = :autoRenews,
                end_date = :endDate,
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
$stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
$stmt->bindParam(':provider', $provider, SQLITE3_TEXT);
$stmt->bindParam(':contractNumber', $contractNumber, SQLITE3_TEXT);
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':initialTermMonths', $initialTermMonths, SQLITE3_INTEGER);
$stmt->bindParam(':renewalTermMonths', $renewalTermMonths, SQLITE3_INTEGER);
$stmt->bindParam(':autoRenews', $autoRenews, SQLITE3_INTEGER);
$stmt->bindParam(':endDate', $endDate, SQLITE3_TEXT);
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
    $text = $isEdit ? 'contract_updated_successfully' : 'contract_added_successfully';
    echo json_encode(['success' => true, 'status' => 'Success', 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
?>
