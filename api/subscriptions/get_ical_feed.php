<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- convert_currency: whether to convert to the main currency (boolean) default false.
- api_key: the API key of the user.

It returns a downloadable VCAL file with the active subscriptions
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/currency_rates.php';
require_once '../../includes/ical_helper.php';
require_once '../../includes/subscription_payers.php';
require_once '../../includes/subscription_categories.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;

    if (!$apiKey) {
        $response = [
            "success" => false,
            "title" => "Missing parameters"
        ];
        echo json_encode($response);
        exit;
    }

    function getPriceConverted($price, $currency, $database)
    {
        return wallos_convert_price($price, $currency, $database);
    }

    // Get user from API key
    $sql = "SELECT * FROM user WHERE api_key = :apiKey";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':apiKey', $apiKey);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    // If the user is not found, return an error
    if (!$user) {
        $response = [
            "success" => false,
            "title" => "Invalid API key"
        ];
        echo json_encode($response);
        exit;
    }

    $userId = $user['id'];
    $userCurrencyId = $user['main_currency'];

    // Get last exchange update date for user
    $sql = "SELECT * FROM last_exchange_update WHERE user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $lastExchangeUpdate = $result->fetchArray(SQLITE3_ASSOC);

    $canConvertCurrency = empty($lastExchangeUpdate['date']) ? false : true;

    // Get currencies for user
    $sql = "SELECT * FROM currencies WHERE user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $currencies = [];
    while ($currency = $result->fetchArray(SQLITE3_ASSOC)) {
        $currencies[$currency['id']] = $currency;
    }

    // Get categories for user
    $sql = "SELECT * FROM categories WHERE user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $categories = [];
    while ($category = $result->fetchArray(SQLITE3_ASSOC)) {
        $categories[$category['id']] = $category['name'];
    }

    // Get members for user
    $sql = "SELECT * FROM household WHERE user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $members = [];
    while ($member = $result->fetchArray(SQLITE3_ASSOC)) {
        $members[$member['id']] = $member['name'];
    }

    // Get payment methods for user
    $sql = "SELECT * FROM payment_methods WHERE user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $paymentMethods = [];
    while ($paymentMethod = $result->fetchArray(SQLITE3_ASSOC)) {
        $paymentMethods[$paymentMethod['id']] = $paymentMethod['name'];
    }

    $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 ORDER BY next_payment ASC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        $subscriptions = array();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subscriptions[] = $row;
        }
    }

    $subscriptionsToReturn = array();

    // Get notification settings
    $notificationQuery = "SELECT days FROM notification_settings WHERE user_id = :userId";
    $notificationQueryStmt = $db->prepare($notificationQuery);
    $notificationQueryStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $notificationResult = $notificationQueryStmt->execute();
    $globalNotificationDays = 1; // Default value
    if ($row = $notificationResult->fetchArray(SQLITE3_ASSOC)) {
        $globalNotificationDays = $row['days'];
    }

    $payersBySubscription = getPayersBySubscription($db, $subscriptions);
    $categoriesBySubscription = getCategoriesBySubscription($db, $subscriptions);

    foreach ($subscriptions as $subscription) {
        $subscriptionToReturn = $subscription;

        $wasConverted = isset($_REQUEST['convert_currency']) && $_REQUEST['convert_currency'] === 'true' && $canConvertCurrency && $subscription['currency_id'] != $userCurrencyId;
        if ($wasConverted) {
            $subscriptionToReturn['price'] = getPriceConverted($subscription['price'], $subscription['currency_id'], $db);
            // Converted prices are now in the user's main currency, so the
            // symbol shown alongside them has to switch too.
            $subscriptionToReturn['currency_id'] = $userCurrencyId;
        } else {
            $subscriptionToReturn['price'] = $subscription['price'];
        }

        $categoryLabel = payerNamesLabel($categoriesBySubscription[$subscription['id']] ?? [], $categories);
        $subscriptionToReturn['category_name'] = $categoryLabel !== '' ? $categoryLabel : 'No category';
        $payerLabel = payerNamesLabel($payersBySubscription[$subscription['id']] ?? [], $members);
        $subscriptionToReturn['payer_user_name'] = $payerLabel !== '' ? $payerLabel : 'Unknown member';
        $subscriptionToReturn['payment_method_name'] = isset($paymentMethods[$subscription['payment_method_id']]) ? $paymentMethods[$subscription['payment_method_id']] : 'Unknown payment method';

        $subscriptionsToReturn[] = $subscriptionToReturn;
    }

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscriptions.ics"');

    $icsContent = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Wallos//iCalendar//EN\nNAME:Wallos Extended\nX-WR-CALNAME:Wallos Extended\n";

    foreach ($subscriptionsToReturn as $subscription) {
        $subscription['payer_user'] = $subscription['payer_user_name'];
        $subscription['category'] = $subscription['category_name'];
        $subscription['payment_method'] = $subscription['payment_method_name'];
        $subscription['currency'] = isset($currencies[$subscription['currency_id']]) ? $currencies[$subscription['currency_id']]['symbol'] : '';
        $subscription['trigger'] = ($subscription['notify_days_before'] == -1) ? $globalNotificationDays : ($subscription['notify_days_before'] ?: 1);
        $subscription['price'] = number_format($subscription['price'], 2);

        $uid = 'wallos-subscription-' . $subscription['id'] . '@wallos';
        $summary = icalEscape(html_entity_decode($subscription['name'], ENT_QUOTES, 'UTF-8'));
        $notes = icalEscape($subscription['notes']);
        $category = icalEscape($subscription['category']);
        $paymentMethod = icalEscape($subscription['payment_method']);
        $payer = icalEscape($subscription['payer_user']);
        $description = "Price: {$subscription['currency']}{$subscription['price']}\\nCategory: {$category}\\nPayment Method: {$paymentMethod}\\nPayer: {$payer}\\nNotes: {$notes}";
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = (new DateTime($subscription['next_payment']))->format('Ymd');
        $dtend = (new DateTime($subscription['next_payment']))->format('Ymd');
        $location = icalEscape(isset($subscription['url']) ? $subscription['url'] : '');
        $alarm_trigger = '-P' . $subscription['trigger'] . 'D';

        // Notes can now be multi-paragraph Markdown, so these lines
        // (especially DESCRIPTION) can genuinely exceed the RFC 5545
        // 75-octet limit.
        $summaryLine = icalFold('SUMMARY:' . $summary);
        $descriptionLine = icalFold('DESCRIPTION:' . $description);
        $locationLine = icalFold('LOCATION:' . $location);

        $icsContent .= <<<ICS
        BEGIN:VEVENT
        UID:$uid
        DTSTAMP:$dtstamp
        $summaryLine
        $descriptionLine
        DTSTART;VALUE=DATE:$dtstart
        DTEND;VALUE=DATE:$dtend
        $locationLine
        STATUS:CONFIRMED
        TRANSP:OPAQUE
        BEGIN:VALARM
        ACTION:DISPLAY
        DESCRIPTION:Reminder
        TRIGGER:$alarm_trigger
        END:VALARM
        END:VEVENT

        ICS;
    }

    // Household finance extension: contracts, insurances, loans and their deadlines.
    require_once __DIR__ . '/../../includes/cancellation_helpers.php';
    require_once __DIR__ . '/../../includes/financing_calculations.php';
    $icsEscape = fn($text) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8'));
    $icsEvent = function (string $uid, string $summary, string $description, string $date, array $extra = []) use ($icsEscape) {
        $lines = array_merge([
            'BEGIN:VEVENT',
            'UID:' . $uid . '@wallos',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SUMMARY:' . $icsEscape($summary),
            'DESCRIPTION:' . $icsEscape($description),
            'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($date)),
            'STATUS:CONFIRMED',
            'TRANSP:TRANSPARENT',
        ], $extra, ['END:VEVENT']);
        return implode("\n", $lines) . "\n";
    };
    $icsAlarm = fn(int $days) => ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-P' . max(0, $days) . 'D', 'END:VALARM'];
    $icsToday = date('Y-m-d');
    $icsFreq = [1 => 'DAILY', 2 => 'WEEKLY', 3 => 'MONTHLY', 4 => 'YEARLY'];

    foreach (['contracts' => 'contract', 'insurances' => 'insurance'] as $icsTable => $icsKind) {
        $stmt = $db->prepare("SELECT * FROM $icsTable WHERE user_id = :userId AND archived = 0");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $icsResult = $stmt->execute();
        while ($item = $icsResult->fetchArray(SQLITE3_ASSOC)) {
            $icsPrice = ($currencies[$item['currency_id']]['symbol'] ?? '') . number_format((float) $item['price'], 2);
            // Recurring payment from the start date.
            if (!empty($item['start_date']) && isset($icsFreq[(int) $item['cycle']])) {
                $rrule = 'RRULE:FREQ=' . $icsFreq[(int) $item['cycle']] . ';INTERVAL=' . max(1, (int) $item['frequency']);
                if (!empty($item['end_date'])) {
                    $rrule .= ';UNTIL=' . date('Ymd', strtotime($item['end_date']));
                }
                $icsContent .= $icsEvent("wallos-$icsKind-" . $item['id'], $item['name'], "Price: $icsPrice", $item['start_date'], [$rrule]);
            }
            // Next cancellation deadline, with a reminder.
            if (empty($item['cancellation_status'])) {
                $deadlineInfo = getNextCancellationDeadline($item, $icsToday);
                if ($deadlineInfo !== null && $deadlineInfo['deadline'] >= $icsToday) {
                    $icsContent .= $icsEvent(
                        "wallos-$icsKind-deadline-" . $item['id'],
                        'Kündigungsfrist: ' . $item['name'],
                        'Laufzeitende: ' . date('d.m.Y', strtotime($deadlineInfo['term_end'])),
                        $deadlineInfo['deadline'],
                        $icsAlarm((int) ($item['notify_days_before'] ?? 14))
                    );
                }
            }
        }
    }

    $stmt = $db->prepare("SELECT * FROM loans WHERE user_id = :userId AND archived = 0");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $icsResult = $stmt->execute();
    $icsLoans = [];
    while ($loan = $icsResult->fetchArray(SQLITE3_ASSOC)) {
        $icsLoans[] = $loan;
    }
    foreach ($icsLoans as $loan) {
        $icsSymbol = $currencies[$loan['currency_id']]['symbol'] ?? '';
        $icsSchedule = loadLoanSchedule($db, $userId, $loan, $icsToday);
        // One event per instalment for the next 12 months (amounts can differ per period).
        $icsHorizon = date('Y-m-d', strtotime('+12 months'));
        foreach (($icsSchedule['rows'] ?? []) as $schedRow) {
            if ($schedRow['date'] < $icsToday || $schedRow['date'] > $icsHorizon) {
                continue;
            }
            $icsContent .= $icsEvent(
                'wallos-loan-' . $loan['id'] . '-' . $schedRow['n'],
                'Rate: ' . $loan['name'],
                'Rate: ' . $icsSymbol . number_format((float) $schedRow['payment'], 2) . "\nRestschuld: " . $icsSymbol . number_format((float) $schedRow['balance'], 2),
                $schedRow['date']
            );
        }
        if (!empty($loan['fixed_interest_end']) && $loan['fixed_interest_end'] >= $icsToday) {
            $icsContent .= $icsEvent('wallos-loan-fixed-end-' . $loan['id'], 'Zinsbindung endet: ' . $loan['name'], 'Anschlussfinanzierung klären', $loan['fixed_interest_end'], $icsAlarm(365));
        }
    }

    $icsContent .= "END:VCALENDAR\n";
    echo $icsContent;
    $db->close();
    exit;
        


} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}


?>
