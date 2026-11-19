<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/logo_theme_variant.php';
require_once '../../includes/logo_cleanup.php';
require_once '../../includes/subscription_payers.php';
require_once '../../includes/subscription_categories.php';

if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

require_once '../../includes/logo_upload.php';

$isEdit = isset($_POST['id']) && $_POST['id'] != "";
$name = validate($_POST["name"]);
$price = $_POST['price'];
$currencyId = $_POST["currency_id"];
$frequency = $_POST["frequency"];
$cycle = $_POST["cycle"];
$nextPayment = $_POST["next_payment"];
$autoRenew = isset($_POST['auto_renew']) ? true : false;
$startDate = $_POST["start_date"];
$paymentMethodId = $_POST["payment_method_id"];
$payerIds = parsePayerIds($_POST["payer_user_id"] ?? "");
$payerUserId = $payerIds[0] ?? null;
$categoryIds = parsePayerIds($_POST['category_id'] ?? "");
$categoryId = $categoryIds[0] ?? null;
$folderId = isset($_POST['folder_id']) && $_POST['folder_id'] != "" ? intval($_POST['folder_id']) : null;
if ($folderId === 0) {
    $folderId = null;
}
if ($folderId !== null) {
    $folderCheck = $db->prepare("SELECT id FROM folders WHERE id = :id AND user_id = :userId");
    $folderCheck->bindParam(':id', $folderId, SQLITE3_INTEGER);
    $folderCheck->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $folderResult = $folderCheck->execute();
    if (!$folderResult || !$folderResult->fetchArray()) {
        $folderId = null;
    }
}
$notes = validate_markdown($_POST["notes"]);
$url = validate($_POST['url']);
$logoUrl = validate($_POST['logo-url']);
$logo = "";
$logoError = "";
$notify = isset($_POST['notifications']) ? true : false;
$notifyDaysBefore = $_POST['notify_days_before'];
$inactive = isset($_POST['inactive']) ? true : false;
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];
$futurePrice = $_POST['future_price'] ?? '';
$priceEffectiveDate = $_POST['price_effective_date'] ?? '';
$today = date('Y-m-d');

// Price history: when editing, an immediate change of the price field is
// logged with today's date; a "planned price change" (future_price +
// effective date) is stored as pending (applied = 0) and only takes effect
// when the date is reached.
$pendingPriceChange = null;
$logPriceChange = null;
if ($isEdit) {
    $currentStmt = $db->prepare("SELECT price, currency_id FROM subscriptions WHERE id = :id AND user_id = :userId");
    $currentStmt->bindValue(':id', $_POST['id'], SQLITE3_INTEGER);
    $currentStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $currentRow = $currentStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($currentRow !== false && (floatval($currentRow['price']) != floatval($price) || intval($currentRow['currency_id']) != intval($currencyId))) {
        $logPriceChange = [
            'price' => $price,
            'currency_id' => $currencyId,
            'effective_date' => $today,
        ];
    }
    if ($futurePrice !== '' && $priceEffectiveDate !== '' && $priceEffectiveDate > $today) {
        $pendingPriceChange = [
            'price' => floatval($futurePrice),
            'currency_id' => $currencyId,
            'effective_date' => $priceEffectiveDate,
        ];
    }
}

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

if ($replacementSubscriptionId !== null) {
    $ownerCheck = $db->prepare("SELECT id FROM subscriptions WHERE id = :id AND user_id = :userId");
    $ownerCheck->bindParam(':id', $replacementSubscriptionId, SQLITE3_INTEGER);
    $ownerCheck->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $ownerResult = $ownerCheck->execute();
    if (!$ownerResult || !$ownerResult->fetchArray()) {
        $replacementSubscriptionId = null;
    }
}

// Reject any foreign key that doesn't belong to the authenticated user, so a
// subscription can't be attached to another tenant's currency/category/
// household member/payment method (IDs are global auto-increment integers
// and easily enumerable).
function rejectForeignId($message)
{
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Error', 'message' => $message]);
    exit();
}

$currStmt = $db->prepare("SELECT id FROM currencies WHERE id = :id AND user_id = :userId");
$currStmt->bindParam(':id', $currencyId, SQLITE3_INTEGER);
$currStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$currResult = $currStmt->execute();
if (!$currResult || !$currResult->fetchArray()) {
    rejectForeignId('The specified currency does not exist or does not belong to you.');
}

if (!categoriesBelongToUser($db, $userId, $categoryIds)) {
    rejectForeignId('The specified category does not exist or does not belong to you.');
}

if (!payersBelongToUser($db, $userId, $payerIds)) {
    rejectForeignId('The specified household member does not exist or does not belong to you.');
}

if ($paymentMethodId !== null && $paymentMethodId !== '') {
    $pmStmt = $db->prepare("SELECT id FROM payment_methods WHERE id = :id AND (user_id = :userId OR user_id = 0 OR user_id IS NULL)");
    $pmStmt->bindParam(':id', $paymentMethodId, SQLITE3_INTEGER);
    $pmStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $pmResult = $pmStmt->execute();
    if (!$pmResult || !$pmResult->fetchArray()) {
        rejectForeignId('The specified payment method does not exist or does not belong to you.');
    }
}

if ($logoUrl !== "") {
    $result = getLogoFromUrl($logoUrl, '../../images/uploads/logos/', $name, $settings, $i18n);
    if ($result['success']) {
        $logo = $result['filename'];
    } else {
        $logoError = $result['message'];
    }
} else {
    if (!empty($_FILES['logo']['name'])) {
        $fileType = mime_content_type($_FILES['logo']['tmp_name']);
        if (strpos($fileType, 'image') === false) {
            echo translate("fill_all_fields", $i18n);
            exit();
        }
        $logo = resizeAndUploadLogo($_FILES['logo'], '../../images/uploads/logos/', $name, $settings);
        if ($logo === "") {
            $logoError = translate('error_saving_logo', $i18n);
        }
    }
}

$logoTextColor = null;
$logoVariant = null;
$removeBackgroundEnabled = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

// Themed variant generation piggybacks on the same "remove background"
// setting: both only make sense for logos we're already reprocessing, and
// this avoids running pixel classification on every single upload.
if ($logo !== "" && $removeBackgroundEnabled) {
    $logoExtension = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
    $logoPath = '../../images/uploads/logos/' . $logo;

    if ($logoExtension === 'png' || $logoExtension === 'webp') {
        $sourceImage = $logoExtension === 'png' ? imagecreatefrompng($logoPath) : imagecreatefromwebp($logoPath);

        if ($sourceImage !== false) {
            imagealphablending($sourceImage, false);
            imagesavealpha($sourceImage, true);

            $logoTextColor = classifyLogoTextColor($sourceImage);

            if ($logoTextColor !== null) {
                $variantImage = generateThemedLogoVariant($sourceImage);
                $logoVariant = pathinfo($logo, PATHINFO_FILENAME) . '-variant.png';
                imagepng($variantImage, '../../images/uploads/logos/' . $logoVariant);
                imagedestroy($variantImage);
            }

            imagedestroy($sourceImage);
        }
    }
}

if (!$isEdit) {
    $sql = "INSERT INTO subscriptions (
                        name, logo, price, currency_id, next_payment, cycle, frequency, notes,
                        payment_method_id, payer_user_id, category_id, folder_id, notify, inactive, url,
                        notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                        auto_renew, start_date, logo_text_color, logo_variant
                    ) VALUES (
                        :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes,
                        :paymentMethodId, :payerUserId, :categoryId, :folderId, :notify, :inactive, :url,
                        :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                        :autoRenew, :startDate, :logoTextColor, :logoVariant
                    )";
} else {
    $id = $_POST['id'];

    // When the logo is being replaced, remember the old files so they can be
    // removed after the update instead of lingering as orphans.
    $oldLogo = null;
    $oldLogoVariant = null;
    if ($logo != "") {
        $oldLogoStmt = $db->prepare("SELECT logo, logo_variant FROM subscriptions WHERE id = :id AND user_id = :userId");
        $oldLogoStmt->bindParam(':id', $id, SQLITE3_INTEGER);
        $oldLogoStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $oldLogoResult = $oldLogoStmt->execute();
        if ($oldLogoResult && ($oldLogoRow = $oldLogoResult->fetchArray(SQLITE3_ASSOC))) {
            $oldLogo = $oldLogoRow['logo'];
            $oldLogoVariant = $oldLogoRow['logo_variant'];
        }
    }

    $sql = "UPDATE subscriptions SET
                        name = :name,
                        price = :price,
                        currency_id = :currencyId,
                        next_payment = :nextPayment,
                        auto_renew = :autoRenew,
                        start_date = :startDate,
                        cycle = :cycle, 
                        frequency = :frequency, 
                        notes = :notes, 
                        payment_method_id = :paymentMethodId,
                        payer_user_id = :payerUserId, 
                        category_id = :categoryId,
                        folder_id = :folderId,
                        notify = :notify,
                        inactive = :inactive, 
                        url = :url, 
                        notify_days_before = :notifyDaysBefore, 
                        cancellation_date = :cancellationDate, 
                        replacement_subscription_id = :replacement_subscription_id";

    if ($logo != "") {
        $sql .= ", logo = :logo, logo_text_color = :logoTextColor, logo_variant = :logoVariant";
    }

    $sql .= " WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
if ($logo != "") {
    $stmt->bindParam(':logo', $logo, SQLITE3_TEXT);
    $stmt->bindParam(':logoTextColor', $logoTextColor, SQLITE3_TEXT);
    $stmt->bindParam(':logoVariant', $logoVariant, SQLITE3_TEXT);
}
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':nextPayment', $nextPayment, SQLITE3_TEXT);
$stmt->bindParam(':autoRenew', $autoRenew, SQLITE3_INTEGER);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
$stmt->bindParam(':folderId', $folderId, SQLITE3_INTEGER);
$stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindParam(':inactive', $inactive, SQLITE3_INTEGER);
$stmt->bindParam(':url', $url, SQLITE3_TEXT);
$stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
$stmt->bindParam(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    // Maintain the price history.
    if (!$isEdit) {
        $newId = $db->lastInsertRowID();
        $historyStmt = $db->prepare("INSERT INTO subscription_price_changes (subscription_id, user_id, price, currency_id, effective_date, applied)
                                     VALUES (:subscriptionId, :userId, :price, :currencyId, :effectiveDate, 1)");
        $historyStmt->bindValue(':subscriptionId', $newId, SQLITE3_INTEGER);
        $historyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $historyStmt->bindValue(':price', $price, SQLITE3_FLOAT);
        $historyStmt->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
        $historyStmt->bindValue(':effectiveDate', $startDate !== '' ? $startDate : $today, SQLITE3_TEXT);
        $historyStmt->execute();
    } else {
        if ($logPriceChange !== null) {
            $historyStmt = $db->prepare("INSERT INTO subscription_price_changes (subscription_id, user_id, price, currency_id, effective_date, applied)
                                         VALUES (:subscriptionId, :userId, :price, :currencyId, :effectiveDate, 1)");
            $historyStmt->bindValue(':subscriptionId', $id, SQLITE3_INTEGER);
            $historyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $historyStmt->bindValue(':price', $logPriceChange['price'], SQLITE3_FLOAT);
            $historyStmt->bindValue(':currencyId', $logPriceChange['currency_id'], SQLITE3_INTEGER);
            $historyStmt->bindValue(':effectiveDate', $logPriceChange['effective_date'], SQLITE3_TEXT);
            $historyStmt->execute();
        }
        // The planned-change fields represent the single scheduled increase:
        // clear any previous pending row and re-insert when set.
        $clearStmt = $db->prepare("DELETE FROM subscription_price_changes WHERE subscription_id = :subscriptionId AND user_id = :userId AND applied = 0");
        $clearStmt->bindValue(':subscriptionId', $id, SQLITE3_INTEGER);
        $clearStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $clearStmt->execute();

        if ($pendingPriceChange !== null) {
            $historyStmt = $db->prepare("INSERT INTO subscription_price_changes (subscription_id, user_id, price, currency_id, effective_date, applied)
                                         VALUES (:subscriptionId, :userId, :price, :currencyId, :effectiveDate, 0)");
            $historyStmt->bindValue(':subscriptionId', $id, SQLITE3_INTEGER);
            $historyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $historyStmt->bindValue(':price', $pendingPriceChange['price'], SQLITE3_FLOAT);
            $historyStmt->bindValue(':currencyId', $pendingPriceChange['currency_id'], SQLITE3_INTEGER);
            $historyStmt->bindValue(':effectiveDate', $pendingPriceChange['effective_date'], SQLITE3_TEXT);
            $historyStmt->execute();
        }
    }

    $success['status'] = "Success";
    $text = $isEdit ? "updated" : "added";
    $success['message'] = translate('subscription_' . $text . '_successfuly', $i18n);
    if ($logoError !== "") {
        $success['logo_warning'] = $logoError;
    }

    $savedSubscriptionId = $isEdit ? intval($id) : $db->lastInsertRowID();
    // Free trial: the reminder comes a few days before it ends (and once per end date).
    $trialEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['trial_end'] ?? '') ? $_POST['trial_end'] : null;
    $trialStmt = $db->prepare('UPDATE subscriptions SET trial_end = :trial, trial_notified = CASE WHEN trial_end IS :trial THEN trial_notified ELSE NULL END WHERE id = :id AND user_id = :userId');
    $trialStmt->bindValue(':trial', $trialEnd, $trialEnd === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $trialStmt->bindValue(':id', $savedSubscriptionId, SQLITE3_INTEGER);
    $trialStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $trialStmt->execute();
    saveSubscriptionPayers($db, $savedSubscriptionId, $payerIds);
    saveSubscriptionCategories($db, $savedSubscriptionId, $categoryIds);

    // The logo was just replaced: drop the previous files if nothing else uses them.
    if ($isEdit && $logo != "") {
        if ($oldLogo !== null && $oldLogo !== $logo) {
            deleteLogoFileIfUnused($db, $oldLogo, '../../images/uploads/logos/');
        }
        if ($oldLogoVariant !== null && $oldLogoVariant !== $logoVariant) {
            deleteLogoFileIfUnused($db, $oldLogoVariant, '../../images/uploads/logos/');
        }
    }

    header('Content-Type: application/json');
    echo json_encode($success);
    exit();
} else {
    echo translate('error', $i18n) . ": " . $db->lastErrorMsg();
}
$db->close();
?>