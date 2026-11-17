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
    $savedId = $isEdit ? $id : $db->lastInsertRowID();
    require_once '../../includes/contract_types.php';
    $contractType = isset(CONTRACT_TYPES[$_POST['contract_type'] ?? '']) ? $_POST['contract_type'] : 'other';
    $details = contractDetailsFromRequest($contractType, $_POST);
    $previous = json_decode((string) $db->querySingle('SELECT details FROM contracts WHERE id = ' . (int) $savedId), true) ?: [];
    // Service contract: the next service is a deadline in "Deadlines & maintenance" (kept in sync).
    if ($contractType === 'service' && !empty($details['next_service'])) {
        $reminderId = (int) ($previous['reminder_id'] ?? 0);
        $ownReminder = $reminderId && $db->querySingle("SELECT COUNT(*) FROM reminders WHERE id = $reminderId AND user_id = " . (int) $userId);
        $reminder = $db->prepare($ownReminder
            ? 'UPDATE reminders SET name = :name, due_date = :due, interval_months = :interval, notified_for = NULL WHERE id = :id AND user_id = :userId'
            : "INSERT INTO reminders (user_id, kind, category, name, due_date, interval_months, notify_days_before) VALUES (:userId, 'maintenance', 'other', :name, :due, :interval, 30)");
        $reminder->bindValue(':name', translate('service_reminder_name', $i18n) . ': ' . html_entity_decode($name));
        $reminder->bindValue(':due', $details['next_service']);
        $reminder->bindValue(':interval', (int) ($details['service_interval'] ?? 12), SQLITE3_INTEGER);
        $reminder->bindValue(':userId', $userId, SQLITE3_INTEGER);
        if ($ownReminder) {
            $reminder->bindValue(':id', $reminderId, SQLITE3_INTEGER);
        }
        $reminder->execute();
        $details['reminder_id'] = $ownReminder ? $reminderId : $db->lastInsertRowID();
    }
    $typeStmt = $db->prepare('UPDATE contracts SET contract_type = :type, details = :details WHERE id = :id AND user_id = :userId');
    $typeStmt->bindValue(':type', $contractType);
    $typeStmt->bindValue(':details', $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : '');
    $typeStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $typeStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $typeStmt->execute();
    require_once '../../includes/pending_prices.php';
    [$pendingPrice, $pendingDate] = pendingPriceFromRequest($_POST);
    $pendingStmt = $db->prepare('UPDATE contracts SET pending_price = :price, pending_price_date = :date WHERE id = :id AND user_id = :userId');
    $pendingStmt->bindValue(':price', $pendingPrice, $pendingPrice === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $pendingStmt->bindValue(':date', $pendingDate, $pendingDate === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $pendingStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $pendingStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $pendingStmt->execute();
    applyPendingPrices($db, $userId); // a date today or in the past applies at once
    $taxStmt = $db->prepare('UPDATE contracts SET tax_deductible = :tax WHERE id = :id AND user_id = :userId');
    $taxStmt->bindValue(':tax', isset($_POST['tax_deductible']) ? 1 : 0, SQLITE3_INTEGER);
    $taxStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $taxStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $taxStmt->execute();
    $text = $isEdit ? 'contract_updated_successfully' : 'contract_added_successfully';
    echo json_encode(['success' => true, 'status' => 'Success', 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
?>
