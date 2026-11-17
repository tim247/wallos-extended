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

$allowedTypes = ['liability', 'household', 'car', 'life', 'pension', 'disability', 'legal', 'health', 'building', 'pet', 'travel', 'accident', 'other'];

$insurer = validate($_POST['insurer'] ?? '');
$policyNumber = validate($_POST['policy_number'] ?? '');
$insuranceType = validate($_POST['insurance_type'] ?? 'other');
if (!in_array($insuranceType, $allowedTypes)) {
    $insuranceType = mb_substr($insuranceType, 0, 40);
    if ($insuranceType === '' || $insuranceType === '__new') {
        $insuranceType = 'other';
    }
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
    $savedId = $isEdit ? $id : $db->lastInsertRowID();
    $cancelMode = in_array($_POST['cancel_mode'] ?? '', ['cancellable', 'switch_only', 'not_cancellable'], true) ? $_POST['cancel_mode'] : 'cancellable';
    $modeStmt = $db->prepare('UPDATE insurances SET cancel_mode = :mode, initial_term_months = :initialTerm WHERE id = :id AND user_id = :userId');
    $modeStmt->bindValue(':mode', $cancelMode);
    $modeStmt->bindValue(':initialTerm', max(1, intval($_POST['initial_term_months'] ?? 12)), SQLITE3_INTEGER);
    $modeStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $modeStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $modeStmt->execute();
    // Coverage, contact, insured persons, motor data, review date.
    $text = fn(string $key, int $max) => validate(mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max));
    $memberIds = array_map('intval', (array) ($_POST['insured_persons'] ?? []));
    $ownMembers = [];
    $memberResult = $db->query('SELECT id FROM household WHERE user_id = ' . (int) $userId);
    while ($memberRow = $memberResult->fetchArray(SQLITE3_NUM)) {
        $ownMembers[] = (int) $memberRow[0];
    }
    $lastReview = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['last_review'] ?? '') ? $_POST['last_review'] : null;
    $extraStmt = $db->prepare('UPDATE insurances SET coverage_notes = :notes, contact_name = :cname, contact_phone = :cphone, contact_email = :cemail,
        insured_persons = :persons, kfz_sf_class = :sf, kfz_km = :km, kfz_type_class = :typeClass, kfz_region_class = :regionClass, last_review = :review
        WHERE id = :id AND user_id = :userId');
    $extraStmt->bindValue(':notes', $text('coverage_notes', 2000));
    $extraStmt->bindValue(':cname', $text('contact_name', 100));
    $extraStmt->bindValue(':cphone', $text('contact_phone', 40));
    $extraStmt->bindValue(':cemail', filter_var(trim((string) ($_POST['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '');
    $extraStmt->bindValue(':persons', implode(',', array_values(array_intersect($memberIds, $ownMembers))));
    $extraStmt->bindValue(':sf', $text('kfz_sf_class', 10));
    $extraStmt->bindValue(':km', max(0, intval($_POST['kfz_km'] ?? 0)), SQLITE3_INTEGER);
    $extraStmt->bindValue(':typeClass', $text('kfz_type_class', 10));
    $extraStmt->bindValue(':regionClass', $text('kfz_region_class', 10));
    $extraStmt->bindValue(':review', $lastReview, $lastReview === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $extraStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $extraStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $extraStmt->execute();
    require_once '../../includes/insurance_insights.php';
    $parentId = intval($_POST['parent_id'] ?? 0) ?: null;
    if ($parentId !== null) {
        // Only an own, top-level insurance can be the policy (no chains, not itself).
        $parentCheck = $db->prepare('SELECT COUNT(*) FROM insurances WHERE id = :id AND user_id = :userId AND parent_id IS NULL AND id != :self');
        $parentCheck->bindValue(':id', $parentId, SQLITE3_INTEGER);
        $parentCheck->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $parentCheck->bindValue(':self', $savedId, SQLITE3_INTEGER);
        $hasBlocks = $db->querySingle('SELECT COUNT(*) FROM insurances WHERE parent_id = ' . (int) $savedId . ' AND user_id = ' . (int) $userId);
        if (!$parentCheck->execute()->fetchArray()[0] || $hasBlocks) {
            $parentId = null;
        }
    }
    $policyStmt = $db->prepare('UPDATE insurances SET parent_id = :parent, refund_amount = :refund WHERE id = :id AND user_id = :userId');
    $policyStmt->bindValue(':parent', $parentId, $parentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $policyStmt->bindValue(':refund', max(0.0, floatval(str_replace(',', '.', $_POST['refund_amount'] ?? '0'))), SQLITE3_FLOAT);
    $policyStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $policyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $policyStmt->execute();
    // One notice period for the whole policy: building blocks take over its term fields.
    $copy = implode(', ', array_map(fn($f) => "$f = (SELECT p.$f FROM insurances p WHERE p.id = insurances.parent_id)", POLICY_TERM_FIELDS));
    $db->exec("UPDATE insurances SET $copy WHERE user_id = " . (int) $userId . " AND parent_id IS NOT NULL AND (id = " . (int) $savedId . " OR parent_id = " . (int) $savedId . ")");
    require_once '../../includes/pending_prices.php';
    [$pendingPrice, $pendingDate] = pendingPriceFromRequest($_POST);
    $pendingStmt = $db->prepare('UPDATE insurances SET pending_price = :price, pending_price_date = :date WHERE id = :id AND user_id = :userId');
    $pendingStmt->bindValue(':price', $pendingPrice, $pendingPrice === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $pendingStmt->bindValue(':date', $pendingDate, $pendingDate === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $pendingStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $pendingStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $pendingStmt->execute();
    applyPendingPrices($db, $userId); // a date today or in the past applies at once
    $taxStmt = $db->prepare('UPDATE insurances SET tax_deductible = :tax WHERE id = :id AND user_id = :userId');
    $taxStmt->bindValue(':tax', isset($_POST['tax_deductible']) ? 1 : 0, SQLITE3_INTEGER);
    $taxStmt->bindValue(':id', $savedId, SQLITE3_INTEGER);
    $taxStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $taxStmt->execute();
    $text = $isEdit ? 'insurance_updated_successfully' : 'insurance_added_successfully';
    echo json_encode(['success' => true, 'status' => 'Success', 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
?>
