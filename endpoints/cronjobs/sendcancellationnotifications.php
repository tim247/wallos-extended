<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ssrf_helper.php';
require_once __DIR__ . '/../../includes/webhook_helper.php';
require_once __DIR__ . '/../../includes/cancellation_helpers.php';

require __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../libs/PHPMailer/SMTP.php';
require __DIR__ . '/../../libs/PHPMailer/Exception.php';

require 'settimezone.php';
require_once __DIR__ . '/../../includes/webhook_headers.php';
require_once __DIR__ . '/../../includes/subscription_payers.php';
require_once __DIR__ . '/../../includes/subscription_categories.php';
require_once __DIR__ . '/../../includes/instance_config.php';

// Get all user ids
$query = "SELECT id, username, language FROM user";
$stmt = $db->prepare($query);
$usersToNotify = $stmt->execute();

function buildCancellationMessage($name, $perUser)
{
    global $i18n;

    if ($name) {
        $message = sprintf(translate('notification_cancellation_intro_named', $i18n), $name) . "\n";
    } else {
        $message = translate('notification_cancellation_intro', $i18n) . "\n";
    }

    foreach ($perUser as $subscription) {
        $message .= sprintf(
            translate('notification_cancellation_line', $i18n),
            $subscription['name'],
            $subscription['price']
        ) . "\n";
    }

    return $message;
}

while ($userToNotify = $usersToNotify->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToNotify['id'];

    // Load the recipient's own language so the notification is translated per
    // user. The cron context has no session, so $i18n would otherwise stay
    // English for everyone.
    $userLanguage = resolve_language($userToNotify['language'] ?? 'en');
    require __DIR__ . '/../../includes/i18n/' . $userLanguage . '.php';
    if (php_sapi_name() !== 'cli') {
        echo "For user: " . $userToNotify['username'] . "<br />";
    }

    $emailNotificationsEnabled = false;
    $gotifyNotificationsEnabled = false;
    $telegramNotificationsEnabled = false;
    $pushoverNotificationsEnabled = false;
    $discordNotificationsEnabled = false;
    $ntfyNotificationsEnabled = false;
    $webhookNotificationsEnabled = false;

    // Check if email notifications are enabled and get the settings
    $query = "SELECT * FROM email_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $emailNotificationsEnabled = $row['enabled'];
        $email['smtpAddress'] = $row["smtp_address"];
        $email['smtpPort'] = $row["smtp_port"];
        $email['encryption'] = $row["encryption"];
        $email['smtpUsername'] = $row["smtp_username"];
        $email['smtpPassword'] = $row["smtp_password"];
        $email['fromEmail'] = $row["from_email"] ? $row["from_email"] : "wallos@wallosapp.com";
        $email['otherEmails'] = $row["other_emails"];
    }

    // Check if Discord notifications are enabled and get the settings
    $query = "SELECT * FROM discord_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $discordNotificationsEnabled = $row['enabled'];
        $discord['webhook_url'] = $row["webhook_url"];
        $discord['bot_username'] = $row["bot_username"];
        $discord['bot_avatar_url'] = $row["bot_avatar_url"];
    }

    // Check if Gotify notifications are enabled and get the settings
    $query = "SELECT * FROM gotify_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $gotify = [];

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $gotifyNotificationsEnabled = $row['enabled'];
        // The instance server when this account has none of its own. Resolved
        // here, before the SSRF check below reads the address. The token stays
        // the account's own.
        $gotify['serverUrl'] = wallos_gotify_server($db, $row["url"]);
        $gotify['appToken'] = $row["token"];
        $gotify['ignore_ssl'] = $row["ignore_ssl"];
    }

    // Check if Telegram notifications are enabled and get the settings
    $query = "SELECT * FROM telegram_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $telegramNotificationsEnabled = $row['enabled'];
        // The instance bot when this account has no token of its own.
        $telegram['botToken'] = wallos_telegram_bot_token($db, $row["bot_token"]);
        $telegram['chatId'] = $row["chat_id"];
    }

    // Check if Pushover notifications are enabled and get the settings
    $query = "SELECT * FROM pushover_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $pushoverNotificationsEnabled = $row['enabled'];
        $pushover['user_key'] = $row["user_key"];
        // The instance application when this account has no token of its own.
        // The user key stays the account's own.
        $pushover['token'] = wallos_pushover_token($db, $row["token"]);
    }

    // Check if Ntfy notifications are enabled and get the settings
    $query = "SELECT * FROM ntfy_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ntfyNotificationsEnabled = $row['enabled'];
        // The instance server when this account has none of its own. Resolved
        // here, before the SSRF check below reads the host.
        $ntfySettings = wallos_ntfy_settings($db, $row["host"], $row["headers"]);
        $ntfy['host'] = $ntfySettings['host'];
        $ntfy['topic'] = $row["topic"];
        $ntfy['headers'] = $ntfySettings['headers'];
        $ntfy['ignore_ssl'] = $row["ignore_ssl"];
    }

    // Check if webhook notifications are enabled and have cancelation payload set and get the settings
    $query = "SELECT * FROM webhook_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $webhook = [];
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $webhook['url'] = $row["url"];
        $webhook['headers'] = $row["headers"];
        $webhook['cancelation_payload'] = $row["cancelation_payload"];
        $webhook['ignore_ssl'] = $row["ignore_ssl"];
        $webhook['request_method'] = $row["request_method"];
        $webhookNotificationsEnabled = $row['enabled'] && $row['cancelation_payload'] != "";
    }

    // Home Assistant notify service (Wallos Extended): one more channel for these reminders.
    require_once __DIR__ . '/../../includes/homeassistant.php';
    $haNotifyService = homeAssistantNotifyService($db, (int) $userId);
    $haConfig = $haNotifyService ? homeAssistantConfig($db, (int) $userId) : null;

    $notificationsEnabled = $emailNotificationsEnabled || $gotifyNotificationsEnabled || $telegramNotificationsEnabled ||
        $pushoverNotificationsEnabled || $discordNotificationsEnabled ||$ntfyNotificationsEnabled || $webhookNotificationsEnabled
        || $haConfig !== null;

    // If no notifications are enabled, no need to run
    if (!$notificationsEnabled) {
        if (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
        continue;
    } else {
        // Get all currencies
        $currencies = array();
        $query = "SELECT * FROM currencies WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $currencies[$row['id']] = $row;
        }

        // Get all household members
        $query = "SELECT * FROM household WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultHousehold = $stmt->execute();

        $household = [];
        while ($rowHousehold = $resultHousehold->fetchArray(SQLITE3_ASSOC)) {
            $household[$rowHousehold['id']] = $rowHousehold;
        }

        // Get all categories
        $query = "SELECT * FROM categories WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultCategories = $stmt->execute();

        $categories = [];
        while ($rowCategory = $resultCategories->fetchArray(SQLITE3_ASSOC)) {
            $categories[$rowCategory['id']] = $rowCategory;
        }

        // Get current date to check which subscriptions are set to notify for cancellation
        $currentDate = new DateTime('now');
        $currentDate = $currentDate->format('Y-m-d');

        // One-time purchases have no recurring commitment to cancel, and the
        // subscription form clears their cancellation date, so never notify for them.
        $query = "SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = :inactive AND cancellation_date = :cancellationDate AND cycle != :oneTimeCycle AND archived = 0 ORDER BY payer_user_id ASC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':cancellationDate', $currentDate, SQLITE3_TEXT);
        $stmt->bindValue(':oneTimeCycle', 5, SQLITE3_INTEGER);
        $resultSubscriptions = $stmt->execute();

        $notify = [];
        $notifyOtherPayers = [];
        $i = 0;
        $currentDate = new DateTime('now');
        while ($rowSubscription = $resultSubscriptions->fetchArray(SQLITE3_ASSOC)) {
            $allPayerIds = getPayersBySubscription($db, [$rowSubscription])[$rowSubscription['id']] ?? [];
            $entry = [
                'name' => $rowSubscription['name'],
                'price' => $rowSubscription['price'] . $currencies[$rowSubscription['currency_id']]['symbol'],
                'currency' => $currencies[$rowSubscription['currency_id']]['name'],
                'category' => payerNamesLabel(getCategoriesBySubscription($db, [$rowSubscription])[$rowSubscription['id']] ?? [], array_map(fn($category) => $category['name'], $categories)) ?: $categories[$rowSubscription['category_id']]['name'],
                'payer' => payerNamesLabel($allPayerIds, array_map(fn($member) => $member['name'], $household)) ?: $household[$rowSubscription['payer_user_id']]['name'],
                'date' => $rowSubscription['next_payment'],
                'url' => $rowSubscription['url'],
                'notes' => $rowSubscription['notes'],
            ];
            // Every channel hears about it once, under the primary payer.
            // Email goes to each payer on their own address, see $notifyEmail.
            $notify[$rowSubscription['payer_user_id']][$i] = $entry;
            foreach ($allPayerIds as $extraPayerId) {
                if ($extraPayerId != $rowSubscription['payer_user_id']) {
                    $notifyOtherPayers[$extraPayerId][$i] = $entry;
                }
            }
            $i++;
        }

        // Contracts and insurances: notify when the computed cancellation
        // deadline enters the notify window (deduplicated per deadline via
        // last_notified_deadline).
        $todayString = (new DateTime('now'))->format('Y-m-d');
        foreach (['contracts' => 'contract_cancellation_reminder', 'insurances' => 'insurance_cancellation_reminder'] as $table => $labelKey) {
            $query = "SELECT * FROM $table WHERE user_id = :user_id AND notify = 1 AND archived = 0 AND cancellation_status = 0";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $resultItems = $stmt->execute();

            while ($item = $resultItems->fetchArray(SQLITE3_ASSOC)) {
                $deadlineInfo = getNextCancellationDeadline($item, $todayString);
                if ($deadlineInfo === null) {
                    continue;
                }
                $deadline = $deadlineInfo['deadline'];
                $notifyFrom = date('Y-m-d', strtotime($deadline . ' -' . intval($item['notify_days_before']) . ' days'));

                if ($todayString < $notifyFrom || $todayString > $deadline) {
                    continue;
                }
                if ($item['last_notified_deadline'] === $deadline) {
                    continue;
                }

                $payerId = $item['payer_user_id'] ?? $userId;
                $label = translate($labelKey, $i18n);
                $deadlineFormatted = date('d.m.Y', strtotime($deadline));
                $notify[$payerId][$i]['name'] = $label . ": " . $item['name'] . " (" . translate('cancellable_until', $i18n) . " " . $deadlineFormatted . ")";
                $notify[$payerId][$i]['price'] = $item['price'] . ($currencies[$item['currency_id']]['symbol'] ?? '');
                $notify[$payerId][$i]['currency'] = $currencies[$item['currency_id']]['name'] ?? '';
                $notify[$payerId][$i]['category'] = $label;
                $notify[$payerId][$i]['payer'] = $household[$payerId]['name'] ?? '';
                $notify[$payerId][$i]['date'] = $deadline;
                $notify[$payerId][$i]['url'] = $item['url'] ?? '';
                $notify[$payerId][$i]['notes'] = $item['notes'] ?? '';
                $i++;

                $updateStmt = $db->prepare("UPDATE $table SET last_notified_deadline = :deadline WHERE id = :id AND user_id = :user_id");
                $updateStmt->bindValue(':deadline', $deadline, SQLITE3_TEXT);
                $updateStmt->bindValue(':id', $item['id'], SQLITE3_INTEGER);
                $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $updateStmt->execute();
            }
        }

        // Household finance extension: end of a fixed-interest period (12 months
        // ahead, once per date) and overdue meter readings. Both go to the
        // main household member.
        $mainMemberId = array_key_first($household) ?? $userId;
        $mainMemberName = $household[$mainMemberId]['name'] ?? '';

        $stmt = $db->prepare("SELECT * FROM loans WHERE user_id = :user_id AND archived = 0 AND fixed_interest_end IS NOT NULL AND fixed_interest_end != ''");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $resultLoans = $stmt->execute();
        $loansToNotify = [];
        while ($loan = $resultLoans->fetchArray(SQLITE3_ASSOC)) {
            $loansToNotify[] = $loan;
        }
        foreach ($loansToNotify as $loan) {
            $fixedEnd = $loan['fixed_interest_end'];
            $notifyFrom = date('Y-m-d', strtotime($fixedEnd . ' -12 months'));
            if ($todayString < $notifyFrom || $todayString > $fixedEnd || $loan['fixed_interest_notified'] === $fixedEnd) {
                continue;
            }
            $label = translate('fixed_interest_end_reminder', $i18n);
            $notify[$mainMemberId][$i]['name'] = $label . ": " . $loan['name'] . " (" . date('d.m.Y', strtotime($fixedEnd)) . ")";
            $notify[$mainMemberId][$i]['price'] = $loan['principal'] . ($currencies[$loan['currency_id']]['symbol'] ?? '');
            $notify[$mainMemberId][$i]['currency'] = $currencies[$loan['currency_id']]['name'] ?? '';
            $notify[$mainMemberId][$i]['category'] = $label;
            $notify[$mainMemberId][$i]['payer'] = $mainMemberName;
            $notify[$mainMemberId][$i]['date'] = $fixedEnd;
            $notify[$mainMemberId][$i]['url'] = '';
            $notify[$mainMemberId][$i]['notes'] = $loan['notes'] ?? '';
            $i++;

            $updateStmt = $db->prepare("UPDATE loans SET fixed_interest_notified = :fixedEnd WHERE id = :id AND user_id = :user_id");
            $updateStmt->bindValue(':fixedEnd', $fixedEnd, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $loan['id'], SQLITE3_INTEGER);
            $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $updateStmt->execute();
        }

        $stmt = $db->prepare("SELECT m.*, (SELECT MAX(reading_date) FROM meter_readings r WHERE r.meter_id = m.id) AS last_reading_date FROM meters m WHERE m.user_id = :user_id AND m.archived = 0 AND m.reading_reminder_days > 0");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $resultMeters = $stmt->execute();
        $metersToNotify = [];
        while ($meter = $resultMeters->fetchArray(SQLITE3_ASSOC)) {
            $metersToNotify[] = $meter;
        }
        foreach ($metersToNotify as $meter) {
            $reminderDays = intval($meter['reading_reminder_days']);
            // Remind when the last reading AND the last reminder are both older than the interval.
            $overdueBefore = date('Y-m-d', strtotime($todayString . ' -' . $reminderDays . ' days'));
            if (!empty($meter['last_reading_date']) && $meter['last_reading_date'] > $overdueBefore) {
                continue;
            }
            if (!empty($meter['last_reading_reminder']) && $meter['last_reading_reminder'] > $overdueBefore) {
                continue;
            }
            $label = translate('meter_reading_reminder', $i18n);
            $lastReadingText = !empty($meter['last_reading_date']) ? date('d.m.Y', strtotime($meter['last_reading_date'])) : '-';
            $notify[$mainMemberId][$i]['name'] = $label . ": " . $meter['name'];
            $notify[$mainMemberId][$i]['price'] = translate('last_reading', $i18n) . " " . $lastReadingText;
            $notify[$mainMemberId][$i]['currency'] = '';
            $notify[$mainMemberId][$i]['category'] = $label;
            $notify[$mainMemberId][$i]['payer'] = $mainMemberName;
            $notify[$mainMemberId][$i]['date'] = $todayString;
            $notify[$mainMemberId][$i]['url'] = '';
            $notify[$mainMemberId][$i]['notes'] = '';
            $i++;

            $updateStmt = $db->prepare("UPDATE meters SET last_reading_reminder = :today WHERE id = :id AND user_id = :user_id");
            $updateStmt->bindValue(':today', $todayString, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $meter['id'], SQLITE3_INTEGER);
            $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $updateStmt->execute();
        }

        // Price increases recorded by the price_history triggers, each reported once.
        $stmt = $db->prepare("SELECT ph.id, ph.item_type, ph.old_price, ph.new_price, ph.changed_at,
                COALESCE(s.name, c.name, ins.name) AS name, COALESCE(s.currency_id, c.currency_id, ins.currency_id) AS currency_id,
                COALESCE(s.payer_user_id, c.payer_user_id, ins.payer_user_id) AS payer_id
            FROM price_history ph
            LEFT JOIN subscriptions s ON ph.item_type = 'subscription' AND s.id = ph.item_id
            LEFT JOIN contracts c ON ph.item_type = 'contract' AND c.id = ph.item_id
            LEFT JOIN insurances ins ON ph.item_type = 'insurance' AND ins.id = ph.item_id
            WHERE ph.user_id = :user_id AND ph.notified = 0");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $resultChanges = $stmt->execute();
        $changeIds = [];
        while ($change = $resultChanges->fetchArray(SQLITE3_ASSOC)) {
            $changeIds[] = (int) $change['id'];
            if ($change['name'] === null || $change['new_price'] <= $change['old_price'] || $change['changed_at'] < date('Y-m-d', strtotime('-30 days'))) {
                continue;
            }
            $payerId = isset($household[$change['payer_id']]) ? $change['payer_id'] : $mainMemberId;
            $symbol = $currencies[$change['currency_id']]['symbol'] ?? '';
            $label = translate('price_increase_notification', $i18n);
            $pct = $change['old_price'] > 0 ? sprintf(' (+%.1f %%)', ($change['new_price'] / $change['old_price'] - 1) * 100) : '';
            $hint = $change['item_type'] !== 'subscription' ? ' · ' . translate('special_termination_hint', $i18n) : '';
            $notify[$payerId][$i] = [
                'name' => $label . ': ' . $change['name'] . ' ' . $change['old_price'] . $symbol . ' → ' . $change['new_price'] . $symbol . $pct . $hint,
                'price' => $change['new_price'] . $symbol,
                'currency' => $currencies[$change['currency_id']]['name'] ?? '',
                'category' => $label,
                'payer' => $household[$payerId]['name'] ?? '',
                'date' => $todayString,
                'url' => '',
                'notes' => '',
            ];
            $i++;
        }
        if ($changeIds) {
            $db->exec('UPDATE price_history SET notified = 1 WHERE id IN (' . implode(',', $changeIds) . ')');
        }

        // Documents whose warranty / validity ends within 30 days, once per date.
        $stmt = $db->prepare("SELECT f.id, f.filename, f.expires_at, COALESCE(c.name, ins.name, l.name) AS item_name
            FROM item_files f
            LEFT JOIN contracts c ON f.item_type = 'contract' AND c.id = f.item_id
            LEFT JOIN insurances ins ON f.item_type = 'insurance' AND ins.id = f.item_id
            LEFT JOIN loans l ON f.item_type = 'loan' AND l.id = f.item_id
            WHERE f.user_id = :user_id AND f.expires_at IS NOT NULL AND f.expires_at BETWEEN :today AND :until
              AND IFNULL(f.expiry_notified, '') != f.expires_at");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $todayString);
        $stmt->bindValue(':until', date('Y-m-d', strtotime('+30 days')));
        $resultFiles = $stmt->execute();
        $expiredFiles = [];
        while ($expiring = $resultFiles->fetchArray(SQLITE3_ASSOC)) {
            $expiredFiles[] = $expiring;
            $label = translate('file_expires_notification', $i18n);
            $notify[$mainMemberId][$i] = [
                'name' => $label . ': ' . $expiring['filename'] . ($expiring['item_name'] ? ' (' . $expiring['item_name'] . ')' : '') . ' – ' . date('d.m.Y', strtotime($expiring['expires_at'])),
                'price' => '',
                'currency' => '',
                'category' => $label,
                'payer' => $mainMemberName,
                'date' => $expiring['expires_at'],
                'url' => '',
                'notes' => '',
            ];
            $i++;
        }
        foreach ($expiredFiles as $expiring) {
            $update = $db->prepare('UPDATE item_files SET expiry_notified = expires_at WHERE id = :id AND user_id = :user_id');
            $update->bindValue(':id', $expiring['id'], SQLITE3_INTEGER);
            $update->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $update->execute();
        }

        // Annual summary in the first week of January: running costs, tax-deductible part, price changes.
        $summaryYear = (int) date('Y');
        if (date('n') == 1 && date('j') <= 7 && (int) $db->querySingle('SELECT IFNULL(year_summary_sent, 0) FROM user WHERE id = ' . (int) $userId) < $summaryYear) {
            require_once __DIR__ . '/../../includes/household_costs.php';
            $GLOBALS['userId'] = $userId;
            $yearItems = householdCostItems($db, (int) $userId);
            $yearTotal = array_sum(array_column($yearItems, 'monthly')) * 12;
            $yearTax = array_sum(array_column(array_filter($yearItems, fn($item) => !empty($item['tax'])), 'monthly')) * 12;
            $yearChanges = (int) $db->querySingle("SELECT COUNT(*) FROM price_history WHERE user_id = " . (int) $userId
                . " AND new_price > old_price AND changed_at >= '" . ($summaryYear - 1) . "-01-01' AND changed_at < '$summaryYear-01-01'");
            $label = translate('year_summary', $i18n);
            $notify[$mainMemberId][$i] = [
                'name' => sprintf(translate('year_summary_text', $i18n), $summaryYear - 1, number_format($yearTotal, 2, ',', '.'),
                    count($yearItems), number_format($yearTax, 2, ',', '.'), $yearChanges),
                'price' => number_format($yearTotal, 2, ',', '.'),
                'currency' => '',
                'category' => $label,
                'payer' => $mainMemberName,
                'date' => $todayString,
                'url' => '',
                'notes' => '',
            ];
            $i++;
            $db->exec('UPDATE user SET year_summary_sent = ' . $summaryYear . ' WHERE id = ' . (int) $userId);
        }

        // System health, to the administrator only: the nightly backup is missing.
        // (A stopped cron cannot report itself; the admin's dashboard shows that case.)
        if ((int) $userId === 1) {
            require_once __DIR__ . '/../../includes/system_health.php';
            foreach (systemHealthIssues($db) as $issue) {
                $label = translate('system_status', $i18n);
                $notify[$mainMemberId][$i] = [
                    'name' => $label . ': ' . sprintf(translate($issue['key'], $i18n), $issue['since'] ? date('d.m.Y', strtotime($issue['since'])) : '–'),
                    'price' => '',
                    'currency' => '',
                    'category' => $label,
                    'payer' => $mainMemberName,
                    'date' => $todayString,
                    'url' => '',
                    'notes' => '',
                ];
                $i++;
            }
        }

        // Deadlines & maintenance (ID card, passport, TÜV, heating ...), once per due date.
        require_once __DIR__ . '/../../includes/life_admin.php';
        $stmt = $db->prepare("SELECT * FROM reminders WHERE user_id = :user_id AND due_date IS NOT NULL
            AND due_date <= date(:today, '+' || notify_days_before || ' days') AND IFNULL(notified_for, '') != due_date");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $todayString);
        $resultReminders = $stmt->execute();
        $dueReminders = [];
        while ($reminder = $resultReminders->fetchArray(SQLITE3_ASSOC)) {
            $dueReminders[] = $reminder;
        }
        foreach ($dueReminders as $reminder) {
            $label = translate('reminder_cat_' . $reminder['category'], $i18n);
            $notify[$mainMemberId][$i] = [
                'name' => $label . ': ' . $reminder['name'] . ($reminder['holder'] !== '' ? ' (' . $reminder['holder'] . ')' : '')
                    . ' – ' . date('d.m.Y', strtotime($reminder['due_date'])),
                'price' => '',
                'currency' => '',
                'category' => translate('reminders', $i18n),
                'payer' => $mainMemberName,
                'date' => $reminder['due_date'],
                'url' => '',
                'notes' => $reminder['notes'],
            ];
            $i++;
            $update = $db->prepare('UPDATE reminders SET notified_for = due_date WHERE id = :id AND user_id = :user_id');
            $update->bindValue(':id', $reminder['id'], SQLITE3_INTEGER);
            $update->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $update->execute();
        }

        // Inactivity guard: remind the owner 14, 7, 3, 2 and 1 day(s) before the trusted person is contacted.
        require_once __DIR__ . '/../../includes/deadman.php';
        $deadman = deadmanSettings($db, (int) $userId);
        $deadmanLeft = empty($deadman['triggered_at'])
            ? deadmanDaysLeft($deadman, $db->querySingle('SELECT last_seen FROM user WHERE id = ' . (int) $userId), $todayString) : null;
        if ($deadmanLeft !== null && in_array($deadmanLeft, DEADMAN_WARN_DAYS, true) && ($deadman['last_warning'] ?? '') !== $todayString) {
            $label = translate('deadman', $i18n);
            $notify[$mainMemberId][$i] = [
                'name' => sprintf(translate('deadman_warning', $i18n), $deadmanLeft, $deadman['trusted_name'] ?: $deadman['trusted_email']),
                'price' => '',
                'currency' => '',
                'category' => $label,
                'payer' => $mainMemberName,
                'date' => $todayString,
                'url' => '',
                'notes' => '',
            ];
            $i++;
            $update = $db->prepare('UPDATE deadman_settings SET last_warning = :today WHERE user_id = :user_id');
            $update->bindValue(':today', $todayString);
            $update->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $update->execute();
        }

        // Categories over their monthly budget, once per category and month.
        require_once __DIR__ . '/../../includes/household_costs.php';
        $GLOBALS['userId'] = $userId;
        $thisMonth = date('Y-m');
        $budgetItems = householdCostItems($db, (int) $userId);
        $stmt = $db->prepare("SELECT id, name, budget FROM categories WHERE user_id = :user_id AND budget > 0 AND IFNULL(budget_notified, '') != :month");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':month', $thisMonth);
        $resultBudgets = $stmt->execute();
        while ($category = $resultBudgets->fetchArray(SQLITE3_ASSOC)) {
            $spent = 0;
            foreach ($budgetItems as $item) {
                if (($item['category_id'] ?? 0) == $category['id']) {
                    $spent += $item['monthly'];
                }
            }
            if ($spent <= $category['budget']) {
                continue;
            }
            $label = translate('budget', $i18n);
            $notify[$mainMemberId][$i] = [
                'name' => sprintf(translate('category_over_budget', $i18n), $category['name'], number_format($spent, 2, ',', '.'), number_format($category['budget'], 2, ',', '.')),
                'price' => number_format($spent, 2, ',', '.'),
                'currency' => '',
                'category' => $label,
                'payer' => $mainMemberName,
                'date' => $todayString,
                'url' => '',
                'notes' => '',
            ];
            $i++;
            $update = $db->prepare('UPDATE categories SET budget_notified = :month WHERE id = :id AND user_id = :user_id');
            $update->bindValue(':month', $thisMonth);
            $update->bindValue(':id', $category['id'], SQLITE3_INTEGER);
            $update->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $update->execute();
        }

        // Home Assistant: everything in one message to the notify service.
        if ($haConfig !== null && !empty($notify)) {
            $haLines = [];
            foreach ($notify as $entries) {
                foreach ($entries as $entry) {
                    $haLines[] = '• ' . $entry['name'];
                }
            }
            $haError = homeAssistantNotify($haConfig, (int) $userId, $haNotifyService, 'Wallos Extended', implode("\n", $haLines));
            echo $haError === '' ? "Home Assistant notification sent\n" : "Home Assistant notification failed: $haError\n";
        }

        // Email reaches every payer, the other channels the primary one only
        $notifyEmail = $notify;
        foreach ($notifyOtherPayers as $payerId => $entries) {
            foreach ($entries as $index => $entry) {
                $notifyEmail[$payerId][$index] = $entry;
            }
        }

        if (!empty($notify)) {

            // Email notifications if enabled
            if ($emailNotificationsEnabled) {
                // Re-validate at send time: a save-time check alone is bypassable via
                // DNS rebinding between when the host was saved and when the cron fires.
                if (!validate_smtp_host($email['smtpAddress'], (int) $email['smtpPort'], $db)) {
                    echo "SSRF attempt detected for SMTP host. Email notifications not sent.<br />";
                } else {
                    $stmt = $db->prepare('SELECT * FROM user WHERE id = :user_id');
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $defaultUser = $result->fetchArray(SQLITE3_ASSOC);
                    $defaultEmail = $defaultUser['email'];
                    $defaultName = $defaultUser['username'];

                    foreach ($notifyEmail as $payerUserId => $perUser) {
                        $message = buildCancellationMessage('', $perUser);

                        $smtpAuth = (isset($email["smtpUsername"]) && $email["smtpUsername"] != "") || (isset($email["smtpPassword"]) && $email["smtpPassword"] != "");

                        $mail = new PHPMailer(true);
                        $mail->CharSet = "UTF-8";
                        $mail->isSMTP();
                        $mail->Timeout = 15;

                        $mail->Host = $email['smtpAddress'];
                        $mail->SMTPAuth = $smtpAuth;
                        if ($smtpAuth) {
                            $mail->Username = $email['smtpUsername'];
                            $mail->Password = $email['smtpPassword'];
                        }
                        if ($email['encryption'] != "none") {
                            $mail->SMTPSecure = $email['encryption'];
                        }
                        $mail->Port = $email['smtpPort'];

                        $stmt = $db->prepare('SELECT * FROM household WHERE id = :payerId AND user_id = :ownerId');
                        $stmt->bindValue(':payerId', $payerUserId, SQLITE3_INTEGER);
                        $stmt->bindValue(':ownerId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $user = $result->fetchArray(SQLITE3_ASSOC);

                        // A payer without an address is skipped, except the primary
                        // ones, who have always fallen back to the account's email.
                        if (empty($user['email']) && isset($notifyOtherPayers[$payerUserId]) && !isset($notify[$payerUserId])) {
                            echo "No email set for " . ($user['name'] ?? $payerUserId) . ", skipped.<br />";
                            continue;
                        }

                        $emailaddress = !empty($user['email']) ? $user['email'] : $defaultEmail;
                        $name = !empty($user['name']) ? $user['name'] : $defaultName;

                        $mail->setFrom($email['fromEmail'], 'Wallos Extended');
                        $mail->addAddress($emailaddress, $name);

                        if (!empty($email['otherEmails'])) {
                            $list = explode(';', $email['otherEmails']);

                            // Avoid duplicate emails
                            $list = array_unique($list);
                            $list = array_filter($list, function ($value) use ($emailaddress) {
                                return $value !== $emailaddress;
                            });

                            foreach($list as $value) {
                                $mail->addCC(trim($value));
                            }
                        }

                        $mail->Subject = translate('wallos_cancellation_notification', $i18n);
                        $mail->Body = $message;

                        if ($mail->send()) {
                            echo "Email Notifications sent<br />";
                        } else {
                            echo "Error sending notifications: " . $mail->ErrorInfo . "<br />";
                        }
                    }
                }
            }

            // Discord notifications if enabled
            if ($discordNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($discord['webhook_url'], $db, $userId);
                if (!$ssrf) {
                    echo "Discord notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $user = $result->fetchArray(SQLITE3_ASSOC);

                        $title = translate('wallos_notification', $i18n);

                        $message = buildCancellationMessage($user['name'] ?? '', $perUser);

                        $postfields = [
                            'content' => $message
                        ];

                        if (!empty($discord['bot_username'])) {
                            $postfields['username'] = $discord['bot_username'];
                        }

                        if (!empty($discord['bot_avatar_url'])) {
                            $postfields['avatar_url'] = $discord['bot_avatar_url'];
                        }

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $discord['webhook_url']);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json'
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $response = curl_exec($ch);
                        
                        if ($response === false) {
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            echo "Discord Notifications sent<br />";
                        }
                        
                        unset($ch);
                    }
                }
            }

            // Gotify notifications if enabled
            if ($gotifyNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($gotify['serverUrl'], $db, $userId);
                if (!$ssrf) {
                    echo "Gotify notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $user = $result->fetchArray(SQLITE3_ASSOC);

                        $message = buildCancellationMessage($user['name'] ?? '', $perUser);

                        $data = array(
                            'message' => $message,
                            'priority' => 5
                        );

                        $data_string = json_encode($data);

                        $ch = curl_init($gotify['serverUrl'] . '/message?token=' . $gotify['appToken']);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt(
                            $ch,
                            CURLOPT_HTTPHEADER,
                            array(
                                'Content-Type: application/json',
                                'Content-Length: ' . strlen($data_string)
                            )
                        );

                        if ($gotify['ignore_ssl']) {
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        }
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $result = curl_exec($ch);
                        if ($result === false) {
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            echo "Gotify Notifications sent<br />";
                        }
                    }
                }
            }

            // Telegram notifications if enabled
            if ($telegramNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    $message = buildCancellationMessage($user['name'] ?? '', $perUser);

                    $data = array(
                        'chat_id' => $telegram['chatId'],
                        'text' => $message
                    );

                    $data_string = json_encode($data);

                    $ch = curl_init('https://api.telegram.org/bot' . $telegram['botToken'] . '/sendMessage');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen($data_string)
                        )
                    );

                    $result = curl_exec($ch);
                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Telegram Notifications sent<br />";
                    }
                }
            }

            // Pushover notifications if enabled
            if ($pushoverNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    $message = buildCancellationMessage($user['name'] ?? '', $perUser);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, "https://api.pushover.net/1/messages.json");
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'token' => $pushover['token'],
                        'user' => $pushover['user_key'],
                        'message' => $message,
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                    $result = curl_exec($ch);

                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Pushover Notifications sent<br />";
                    }
                    
                    unset($ch);
                }
            }

            // Ntfy notifications if enabled
            if ($ntfyNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($ntfy['host'], $db, $userId);
                if (!$ssrf) {
                    echo "Ntfy notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $user = $result->fetchArray(SQLITE3_ASSOC);

                        $message = buildCancellationMessage($user['name'] ?? '', $perUser);

                        $customheaders = webhook_custom_headers($ntfy["headers"]);

                        $ch = curl_init();

                        $ntfyHost = rtrim($ntfy["host"], '/');
                        $ntfyTopic = $ntfy['topic'];

                        curl_setopt($ch, CURLOPT_URL, $ntfyHost . '/' . $ntfyTopic);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                        if ($ntfy['ignore_ssl']) {
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        }
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $response = curl_exec($ch);
                        
                        if ($response === false) {
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            echo "Ntfy Notifications sent<br />";
                        }
                        
                        unset($ch);
                    }
                }
            }

            // Webhook notifications if enabled
            if ($webhookNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($webhook['url'], $db, $userId);
                if (!$ssrf) {
                    echo "Webhook notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $user = $result->fetchArray(SQLITE3_ASSOC);
                
                        if ($user['name']) {
                            $payer = $user['name'];
                        }
                
                        foreach ($perUser as $subscription) {
                            // Ensure the payload is reset for each subscription
                            $payload = $webhook['cancelation_payload'];
                            $payload = str_replace("{{subscription_name}}", $subscription['name'], $payload);
                            $payload = str_replace("{{subscription_price}}", $subscription['price'], $payload);
                            $payload = str_replace("{{subscription_currency}}", $subscription['currency'], $payload);
                            $payload = str_replace("{{subscription_category}}", $subscription['category'], $payload);
                            $payload = str_replace("{{subscription_payer}}", $payer, $payload);
                            $payload = str_replace("{{subscription_date}}", $subscription['date'], $payload);
                            $payload = str_replace("{{subscription_url}}", $subscription['url'], $payload);
                            $payload = str_replace("{{subscription_notes}}", webhookJsonEscape($subscription['notes']), $payload);
                
                            // Initialize cURL for each subscription
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $webhook['url']);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $webhook['request_method']);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                
                            // Add headers if they exist. The same reading as
                            // every other job: this one used to split the field
                            // into lines, so a value that worked here was a
                            // value that ended the other run, and the other way
                            // round.
                            $customheaders = webhook_headers_with_content_type(webhook_custom_headers($webhook['headers']), $payload);
                            if (!empty($customheaders)) {
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                            }
                
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                            // Handle SSL settings
                            if ($webhook['ignore_ssl']) {
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            }
                            curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);
                
                            // Execute the cURL request
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                            if ($response === false || $httpCode >= 400) {
                                echo "Error sending cancellation notifications: " . curl_error($ch) . "<br />";
                            } else {
                                echo "Webhook Cancellation Notification sent for subscription: " . $subscription['name'] . "<br />";
                            }
                            
                            unset($ch);
                
                            usleep(1000000); // 1s delay between requests
                        }
                    }
                }
            }
            

        } else {
            if (php_sapi_name() !== 'cli') {
                echo "Nothing to notify.<br />";
            }
        }

    }

}

?>
