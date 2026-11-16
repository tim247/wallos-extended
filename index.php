<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/financing_calculations.php';
require_once 'includes/consumption_calculations.php';
require_once 'includes/consumption_insights.php';
require_once 'includes/cancellation_helpers.php';
require_once 'includes/logo_theme_variant.php';
require_once 'includes/html_text.php';
require_once 'includes/upcoming_payments.php';
require_once 'includes/upcoming_cancellations.php';
require_once 'includes/household_costs.php';

function formatPrice($price, $currencyCode, $currencies)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);
    if (strstr($formattedPrice, $currencyCode)) {
        $symbol = $currencyCode;

        foreach ($currencies as $currency) {

            if ($currency['code'] === $currencyCode) {
                if ($currency['symbol'] != "") {
                    $symbol = $currency['symbol'];
                }
                break;
            }
        }
        $formattedPrice = str_replace($currencyCode, $symbol, $formattedPrice);
    }

    return $formattedPrice;
}

function formatDate($date, $lang = 'en')
{
    $currentYear = date('Y');
    $dateYear = date('Y', strtotime($date));

    // Determine the date format based on whether the year matches the current year
    $dateFormat = ($currentYear == $dateYear) ? 'MMM d' : 'MMM yyyy';

    // Try to create an IntlDateFormatter; if it fails, fallback to 'en'
    try {
        $formatter = new IntlDateFormatter(
            $lang,
            IntlDateFormatter::SHORT,
            IntlDateFormatter::NONE,
            null,
            null,
            $dateFormat
        );

        if (!$formatter) {
            throw new Exception('Failed to create IntlDateFormatter with language: ' . $lang);
        }
    } catch (Throwable $e) {
        $lang = 'en'; // Fallback to English on error
        $formatter = new IntlDateFormatter(
            $lang,
            IntlDateFormatter::SHORT,
            IntlDateFormatter::NONE,
            null,
            null,
            $dateFormat
        );
    }

    // Format the date
    $formattedDate = $formatter->format(new DateTime($date));

    return $formattedDate;
}

// Get the first name of the user
$stmt = $db->prepare("SELECT username, firstname FROM user WHERE id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);
$first_name = $user['firstname'] ?? $user['username'] ?? '';

$today = date('Y-m-d');
$endOfYear = date('Y') . '-12-31';

// Fetch the enabled subscriptions up for payment using the user's dashboard setting.
$upcomingSubscriptions = get_upcoming_payments(
    $db,
    $userId,
    $settings['upcoming_payments_limit'] ?? 3
);

// Fetch enabled subscriptions with manual renewal that are overdue (excluding already-paid ones)
$stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, next_payment, inactive, auto_renew, cycle, frequency, paid_at FROM subscriptions WHERE user_id = :userId AND next_payment < date('now') AND auto_renew = 0 AND archived = 0 AND inactive = 0 AND cycle != 5 ORDER BY next_payment ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$overdueSubscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if (!isPaidThisCycle($row['paid_at'] ?? null, $row['cycle'], $row['frequency'], $row['next_payment'])) {
        $overdueSubscriptions[] = $row;
    }
}
$hasOverdueSubscriptions = !empty($overdueSubscriptions);

// Fetch the subscriptions whose cancellation reminder is still ahead (shared with
// the statistics page, so both stay in step).
$upcomingCancellations = get_upcoming_cancellations($db, $userId);
$hasUpcomingCancellations = !empty($upcomingCancellations);

require_once 'includes/stats_calculations.php';

$mainCurrencyCode = $currencies[$userData['main_currency']]['code'] ?? 'EUR';
$today = date('Y-m-d'); // stats_calculations.php leaves a DateTime in $today

// Get AI Recommendations for user
$stmt = $db->prepare("SELECT * FROM ai_recommendations WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$aiRecommendations = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $aiRecommendations[] = $row;
}

/**
 * Computes the next recurring payment date on/after $today from a start date
 * and a Wallos cycle/frequency pair (cycle: 1=days, 2=weeks, 3=months, 4=years).
 */
function computeNextPaymentDate(?string $startDate, $cycle, $frequency, string $today): ?string
{
    if (empty($startDate)) {
        return null;
    }
    $unit = [1 => 'days', 2 => 'weeks', 3 => 'months', 4 => 'years'][(int) $cycle] ?? null;
    if ($unit === null) {
        return null;
    }
    $step = max(1, (int) $frequency);
    $date = new DateTime($startDate);
    $guard = 0;
    while ($date->format('Y-m-d') < $today && $guard < 6000) {
        $date->modify('+' . $step . ' ' . $unit);
        $guard++;
    }
    return $date->format('Y-m-d');
}

/* ---------- contracts ---------- */
$contractsMonthly = 0.0;
$contractCount = 0;
$upcomingDeadlines = [];
$upcomingPayments = []; // contracts + insurances recurring payments
$stmt = $db->prepare("SELECT * FROM contracts WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $contractCount++;
    $price = getPriceConverted(floatval($row['price']), $row['currency_id'], $db, $userId);
    $contractsMonthly += getPricePerMonth($row['cycle'], $row['frequency'], $price);
    $nextPay = computeNextPaymentDate($row['start_date'] ?? null, $row['cycle'], $row['frequency'], $today);
    if ($nextPay !== null && (empty($row['end_date']) || $nextPay <= $row['end_date'])) {
        $upcomingPayments[] = [
            'type' => 'contract',
            'icon' => 'fa-file-signature',
            'name' => $row['name'],
            'date' => $nextPay,
            'price' => floatval($row['price']),
            'currency_id' => $row['currency_id'],
            'link' => 'contracts.php',
        ];
    }
    if (!$row['cancellation_status']) {
        $deadlineInfo = getNextCancellationDeadline($row, $today);
        if ($deadlineInfo !== null) {
            $upcomingDeadlines[] = [
                'type' => 'contract',
                'name' => $row['name'],
                'date' => $deadlineInfo['deadline'],
                'link' => 'contracts.php',
            ];
        }
    }
}

/* ---------- insurances ---------- */
$insurancesMonthly = 0.0;
$insuranceCount = 0;
$stmt = $db->prepare("SELECT * FROM insurances WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $insuranceCount++;
    $price = getPriceConverted(floatval($row['price']), $row['currency_id'], $db, $userId);
    $insurancesMonthly += getPricePerMonth($row['cycle'], $row['frequency'], $price);
    $nextPay = computeNextPaymentDate($row['start_date'] ?? null, $row['cycle'], $row['frequency'], $today);
    if ($nextPay !== null && (empty($row['end_date']) || $nextPay <= $row['end_date'])) {
        $upcomingPayments[] = [
            'type' => 'insurance',
            'icon' => 'fa-shield-halved',
            'name' => $row['name'],
            'date' => $nextPay,
            'price' => floatval($row['price']),
            'currency_id' => $row['currency_id'],
            'link' => 'insurance.php',
        ];
    }
    if (!$row['cancellation_status']) {
        $deadlineInfo = getNextCancellationDeadline($row, $today);
        if ($deadlineInfo !== null) {
            $upcomingDeadlines[] = [
                'type' => 'insurance',
                'name' => $row['name'],
                'date' => $deadlineInfo['deadline'],
                'link' => 'insurance.php',
            ];
        }
    }
}

/* ---------- loans ---------- */
$loansMonthly = 0.0;
$loansRemaining = 0.0;
$loanProgress = [];
$stmt = $db->prepare("SELECT * FROM loans WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $schedule = loadLoanSchedule($db, $userId, $row, $today);
    if ($schedule['error'] !== null) {
        continue;
    }
    if ($schedule['balance_as_of'] > 0) {
        $loansMonthly += getPriceConverted(floatval($row['monthly_rate']), $row['currency_id'], $db, $userId);
    }
    $remaining = getPriceConverted($schedule['balance_as_of'], $row['currency_id'], $db, $userId);
    $loansRemaining += $remaining;

    // Next scheduled instalment (for the "upcoming" panel).
    $nextLoanDate = null;
    $nextLoanAmount = null;
    foreach (($schedule['rows'] ?? []) as $schedRow) {
        if ($schedRow['date'] >= $today) {
            $nextLoanDate = $schedRow['date'];
            $nextLoanAmount = $schedRow['payment'];
            break;
        }
    }

    $loanProgress[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'percent' => $row['principal'] > 0 ? min(100, round($schedule['paid_principal_as_of'] / $row['principal'] * 100, 1)) : 0,
        'remaining' => $schedule['balance_as_of'],
        'currency_code' => $currencies[$row['currency_id']]['code'] ?? $mainCurrencyCode,
        'payoff' => $schedule['payoff_date'],
        'next_payment' => $nextLoanDate,
        'next_amount' => $nextLoanAmount,
        'fixed_interest_end' => $row['fixed_interest_end'] ?? null,
        'currency_id' => $row['currency_id'],
    ];
}

/* ---------- meters ---------- */
$meterSnapshots = [];
$meterOutliers = [];
$meterMonthCompare = [];
$pvMonthlyEarnings = 0.0;
$consumptionMonthly = 0.0;
$stmt = $db->prepare("SELECT * FROM meters WHERE user_id = :userId AND archived = 0 ORDER BY sort_order = 0, sort_order, meter_type ASC, name COLLATE NOCASE ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$meterTypeMeta = [
    'electricity' => ['icon' => 'fa-bolt', 'unit' => 'kWh'],
    'water' => ['icon' => 'fa-droplet', 'unit' => 'm³'],
    'gas' => ['icon' => 'fa-fire', 'unit' => 'm³'],
    'pv_feed_in' => ['icon' => 'fa-solar-panel', 'unit' => 'kWh'],
    'pv_generation' => ['icon' => 'fa-sun', 'unit' => 'kWh'],
];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rStmt = $db->prepare("SELECT reading_date, reading_value, is_meter_change FROM meter_readings WHERE meter_id = :meterId AND user_id = :userId ORDER BY reading_date ASC");
    $rStmt->bindValue(':meterId', $row['id'], SQLITE3_INTEGER);
    $rStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $rResult = $rStmt->execute();
    $readings = [];
    while ($r = $rResult->fetchArray(SQLITE3_ASSOC)) {
        $readings[] = $r;
    }
    $computed = computeConsumptionSegments($readings);
    if (!in_array($row['meter_type'], ['pv_feed_in', 'pv_generation'], true) && ($outlier = detectOutlier($computed['segments'])) !== null) {
        $meterOutliers[] = [$row['name'], $outlier];
    }
    $buckets = computeMonthlyBuckets($computed['segments']);
    if ($row['meter_type'] !== 'pv_generation' && ($compare = lastMonthComparison($computed['segments'], $buckets, date('Y-m-d'))) !== null) {
        $meterMonthCompare[] = $compare + ['name' => $row['name'], 'icon' => $meterTypeMeta[$row['meter_type']]['icon'] ?? 'fa-gauge',
            'unit' => $meterTypeMeta[$row['meter_type']]['unit'] ?? $row['unit'], 'is_pv' => $row['meter_type'] === 'pv_feed_in'];
    }
    // Keep only the last 6 months for the snapshot.
    $buckets = array_slice($buckets, -6, 6, true);
    $forecast = computeForecast($computed['segments'], $endOfYear);

    $isPv = $row['meter_type'] === 'pv_feed_in';
    if ($isPv && $forecast !== null && $forecast['per_day'] > 0) {
        $pvMonthlyEarnings += $forecast['per_day'] * 30.44 * floatval($row['price_per_unit']);
    } elseif ($forecast !== null && $forecast['per_day'] > 0 && $row['meter_type'] !== 'pv_generation') {
        // Same estimate as stats.php: forecast units per month * price + base fee.
        $conversion = ($row['meter_type'] === 'gas' && !empty($row['conversion_factor'])) ? floatval($row['conversion_factor']) : 1.0;
        $consumptionMonthly += getPriceConverted($forecast['per_day'] * 30.44 * $conversion * floatval($row['price_per_unit']) + floatval($row['base_fee_monthly']), $row['currency_id'], $db, $userId);
    }

    $meterSnapshots[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'type' => $row['meter_type'],
        'icon' => $meterTypeMeta[$row['meter_type']]['icon'] ?? 'fa-gauge',
        'unit' => $meterTypeMeta[$row['meter_type']]['unit'] ?? $row['unit'],
        'monthly' => $buckets,
        'is_pv' => $isPv,
        'forecast' => $forecast,
    ];
}

/* ---------- upcoming events (merged) ---------- */
$upcomingEvents = [];

// Subscription payments within the next 30 days.
$stmt = $db->prepare("SELECT name, next_payment, price, currency_id FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0 AND cycle != 5 AND next_payment >= date('now') AND next_payment <= date('now', '+30 days') ORDER BY next_payment ASC LIMIT 10");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $upcomingEvents[] = [
        'type' => 'subscription',
        'icon' => 'fa-credit-card',
        'name' => $row['name'],
        'date' => $row['next_payment'],
        'detail' => formatPrice($row['price'], $currencies[$row['currency_id']]['code'] ?? $mainCurrencyCode, $currencies),
        'link' => 'subscriptions.php',
        'class' => '',
    ];
}

// Contract & insurance payments within the next 30 days.
$next30 = date('Y-m-d', strtotime('+30 days'));
foreach ($upcomingPayments as $payment) {
    if ($payment['date'] >= $today && $payment['date'] <= $next30) {
        $upcomingEvents[] = [
            'type' => $payment['type'],
            'icon' => $payment['icon'],
            'name' => $payment['name'],
            'date' => $payment['date'],
            'detail' => formatPrice($payment['price'], $currencies[$payment['currency_id']]['code'] ?? $mainCurrencyCode, $currencies),
            'link' => $payment['link'],
            'class' => '',
        ];
    }
}

// Loan instalments within the next 30 days.
foreach ($loanProgress as $loan) {
    if (!empty($loan['next_payment']) && $loan['next_payment'] >= $today && $loan['next_payment'] <= $next30) {
        $upcomingEvents[] = [
            'type' => 'financing',
            'icon' => 'fa-landmark',
            'name' => $loan['name'],
            'date' => $loan['next_payment'],
            'detail' => formatPrice($loan['next_amount'], $currencies[$loan['currency_id']]['code'] ?? $mainCurrencyCode, $currencies),
            'link' => 'financing.php?id=' . $loan['id'],
            'class' => '',
        ];
    }
}

// Deadlines & maintenance inside their reminder window (overdue ones too).
require_once 'includes/life_admin.php';
$reminderStmt = $db->prepare("SELECT * FROM reminders WHERE user_id = :userId AND due_date IS NOT NULL
    AND due_date <= date('now', '+' || notify_days_before || ' days') ORDER BY due_date");
$reminderStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$reminderResult = $reminderStmt->execute();
while ($reminderResult && $reminder = $reminderResult->fetchArray(SQLITE3_ASSOC)) {
    $upcomingEvents[] = [
        'type' => 'reminder',
        'icon' => REMINDER_CATEGORIES[$reminder['category']][1] ?? 'fa-calendar-check',
        'name' => $reminder['name'] . ($reminder['holder'] !== '' ? ' · ' . $reminder['holder'] : ''),
        'date' => $reminder['due_date'],
        'detail' => translate('reminder_cat_' . $reminder['category'], $i18n),
        'link' => 'reminders.php',
        'class' => $reminder['due_date'] < $today ? 'warning' : '',
    ];
}

// End of a fixed-interest period within the next 12 months.
foreach ($loanProgress as $loan) {
    if (!empty($loan['fixed_interest_end']) && $loan['fixed_interest_end'] >= $today && $loan['fixed_interest_end'] <= date('Y-m-d', strtotime('+12 months'))) {
        $upcomingEvents[] = [
            'type' => 'financing',
            'icon' => 'fa-percent',
            'name' => $loan['name'],
            'date' => $loan['fixed_interest_end'],
            'detail' => translate('fixed_interest_end_reminder', $i18n),
            'link' => 'financing.php?id=' . $loan['id'],
            'class' => 'warning',
        ];
    }
}

// Cancellation deadlines within the next 60 days.
foreach ($upcomingDeadlines as $deadline) {
    $daysTo = (strtotime($deadline['date']) - strtotime($today)) / 86400;
    if ($daysTo >= 0 && $daysTo <= 60) {
        $upcomingEvents[] = [
            'type' => $deadline['type'],
            'icon' => 'fa-bell',
            'name' => $deadline['name'],
            'date' => $deadline['date'],
            'detail' => translate('cancellation_deadline', $i18n),
            'link' => $deadline['link'],
            'class' => 'warning',
        ];
    }
}

// Announced price increases.
$stmt = $db->prepare("SELECT pc.price, pc.effective_date, s.name, s.price AS old_price, s.currency_id FROM subscription_price_changes pc JOIN subscriptions s ON s.id = pc.subscription_id WHERE pc.user_id = :userId AND pc.applied = 0 AND s.archived = 0 ORDER BY pc.effective_date ASC LIMIT 10");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $upcomingEvents[] = [
        'type' => 'price_increase',
        'icon' => 'fa-arrow-trend-up',
        'name' => $row['name'],
        'date' => $row['effective_date'],
        'detail' => translate('price_increase_to', $i18n) . ' ' . formatPrice($row['price'], $currencies[$row['currency_id']]['code'] ?? $mainCurrencyCode, $currencies),
        'link' => 'subscriptions.php',
        'class' => 'warning',
    ];
}

/* ---------- price increases (last 12 months, from the price_history triggers) ---------- */
$priceIncreases = [];
$stmt = $db->prepare("SELECT ph.item_type, ph.old_price, ph.new_price, ph.changed_at,
        COALESCE(s.name, c.name, i.name) AS name, COALESCE(s.currency_id, c.currency_id, i.currency_id) AS currency_id
    FROM price_history ph
    LEFT JOIN subscriptions s ON ph.item_type = 'subscription' AND s.id = ph.item_id AND s.archived = 0
    LEFT JOIN contracts c ON ph.item_type = 'contract' AND c.id = ph.item_id AND c.archived = 0
    LEFT JOIN insurances i ON ph.item_type = 'insurance' AND i.id = ph.item_id AND i.archived = 0
    WHERE ph.user_id = :userId AND ph.new_price > ph.old_price AND ph.changed_at >= date('now', '-12 months')
    ORDER BY ph.changed_at DESC LIMIT 8");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] !== null) {
        $priceIncreases[] = $row;
    }
}

// First steps: shown until the basics are set up.
$costItems = householdCostItems($db, $userId);
$countFor = function (string $sql) use ($db, $userId): int {
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return (int) ($stmt->execute()->fetchArray()[0] ?? 0);
};
$notificationTables = ['email', 'discord', 'gotify', 'telegram', 'pushover', 'ntfy', 'webhook'];
$notificationsOn = array_sum(array_map(fn($t) => $countFor("SELECT COUNT(*) FROM {$t}_notifications WHERE user_id = :userId AND enabled = 1"), $notificationTables)) > 0;
$firstSteps = [
    ['currency', 'settings.php', true],
    ['household', 'settings.php', $countFor('SELECT COUNT(*) FROM household WHERE user_id = :userId') > 1],
    ['notifications', 'settings.php', $notificationsOn],
    ['first_entries', 'import.php', count($costItems) >= 3],
];
$showFirstSteps = count(array_filter(array_column($firstSteps, 2))) < count($firstSteps) && count($costItems) < 10;

// Hints: system health (admin only), categories over budget and basic insurances that are missing.
$householdHints = [];
foreach ($meterOutliers as [$outlierName, $outlier]) {
    $householdHints[] = ['fa-triangle-exclamation', sprintf(translate('outlier_hint', $i18n), $outlierName, round(($outlier['ratio'] - 1) * 100)), 'consumption.php'];
}
if ($isAdmin) {
    require_once 'includes/system_health.php';
    foreach (systemHealthIssues($db) as $issue) {
        $householdHints[] = ['fa-triangle-exclamation', sprintf(translate($issue['key'], $i18n),
            $issue['since'] ? date('d.m.Y', strtotime($issue['since'])) : '–'), 'admin.php'];
    }
}
foreach (categoryBudgets($db, $userId, $costItems) as $cb) {
    if ($cb['spent'] > $cb['budget']) {
        $householdHints[] = ['fa-wallet', sprintf(translate('category_over_budget', $i18n), $cb['name'],
            CurrencyFormatter::format($cb['spent'], $mainCurrencyCode), CurrencyFormatter::format($cb['budget'], $mainCurrencyCode)), 'settings.php'];
    }
}
foreach (missingInsurances($db, $userId) as $type) {
    $householdHints[] = ['fa-shield-halved', sprintf(translate('insurance_missing', $i18n), translate('insurance_type_' . $type, $i18n)), 'insurance.php'];
}

usort($upcomingEvents, fn($a, $b) => strcmp($a['date'], $b['date']));
$upcomingEvents = array_slice($upcomingEvents, 0, 10);

/* ---------- totals ---------- */
$subscriptionsMonthly = $totalCostPerMonth ?? 0;
$totalMonthlyBurden = $subscriptionsMonthly + $contractsMonthly + $insurancesMonthly + $loansMonthly + $consumptionMonthly;

$burdenSplitDataPoints = [];
if ($subscriptionsMonthly > 0) {
    $burdenSplitDataPoints[] = ['label' => translate('subscriptions', $i18n), 'y' => round($subscriptionsMonthly, 2)];
}
if ($contractsMonthly > 0) {
    $burdenSplitDataPoints[] = ['label' => translate('contracts', $i18n), 'y' => round($contractsMonthly, 2)];
}
if ($insurancesMonthly > 0) {
    $burdenSplitDataPoints[] = ['label' => translate('insurances', $i18n), 'y' => round($insurancesMonthly, 2)];
}
if ($loansMonthly > 0) {
    $burdenSplitDataPoints[] = ['label' => translate('financing', $i18n), 'y' => round($loansMonthly, 2)];
}
if ($consumptionMonthly > 0) {
    $burdenSplitDataPoints[] = ['label' => translate('consumption', $i18n), 'y' => round($consumptionMonthly, 2)];
}

?>

<section class="contain dashboard">
    <?php
        if ($isAdmin && $settings['update_notification']) {
            if (!is_null($settings['latest_version'])) {
                $latestVersion = $settings['latest_version'];
                if (extVersionCompare($installedVersion, $latestVersion) < 0) {
                    ?>
                    <div class="update-banner">
                        <div class="update-banner-icon">
                            <i class="fa-solid fa-arrow-up"></i>
                        </div>
                        <div class="update-banner-text">
                            <strong><?= translate('new_version_available', $i18n) ?></strong>
                            <span>
                                <?= translate('current_version', $i18n) ?>: <?= htmlspecialchars($installedVersion) ?>
                                <i class="fa-solid fa-arrow-right"></i>
                                <b><?= htmlspecialchars($latestVersion) ?></b>
                            </span>
                        </div>
                        <a class="update-banner-link"
                            href="https://github.com/<?= $extRepository ?>/releases/tag/<?= htmlspecialchars($latestVersion) ?>"
                            target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                            <?= translate('release_notes', $i18n) ?>
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>
                    <?php
                }
            }
    }
    if ($demoMode) {
        ?>
        <div class="demo-banner">
            Running in <b>Demo Mode</b>, certain actions and settings are disabled.<br>
            The database will be reset every 120 minutes.
        </div>
        <?php
    }
    ?>
    <h1><?= translate('hello', $i18n) ?> <?= htmlspecialchars($first_name) ?></h1>

    <form class="dashboard-search" action="search.php" method="get" role="search"
      data-empty="<?= htmlspecialchars(translate('search_no_results', $i18n)) ?>"
      data-all="<?= htmlspecialchars(translate('search_show_all', $i18n)) ?>">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" name="q" autocomplete="off" minlength="2" role="combobox" aria-expanded="false"
        aria-controls="dashboard-search-results" aria-autocomplete="list"
        aria-label="<?= translate('search', $i18n) ?>" placeholder="<?= translate('search_placeholder', $i18n) ?>">
      <kbd class="dashboard-search-key" aria-hidden="true">/</kbd>
      <div class="dashboard-search-results" id="dashboard-search-results" role="listbox" hidden></div>
    </form>

    <div class="statistics dashboard-tiles">
        <div class="statistic highlight">
            <span><?= CurrencyFormatter::format($totalMonthlyBurden, $mainCurrencyCode) ?></span>
            <div class="title"><?= translate('total_monthly_burden', $i18n) ?></div>
        </div>
        <?php
        require_once 'includes/finances.php';
        $dashIncome = array_sum(array_column(financeIncomes($db, $userId), 'monthly'));
        if ($dashIncome > 0):
            // Same basis as finances.php and the report (household_costs.php).
            $dashLeft = $dashIncome - array_sum(array_column($costItems, 'monthly'))
                - array_sum(array_column(financeExpenses($db, $userId), 'monthly')) - array_sum(array_column(financeGoals($db, $userId), 'monthly_needed'));
            ?>
            <a class="statistic <?= $dashLeft < 0 ? 'negative' : '' ?>" href="finances.php">
                <span><?= CurrencyFormatter::format($dashLeft, $mainCurrencyCode) ?></span>
                <div class="title"><i class="fa-solid fa-sack-dollar"></i> <?= translate('left_monthly', $i18n) ?></div>
            </a>
        <?php endif; ?>
        <a class="statistic" href="subscriptions.php">
            <span><?= CurrencyFormatter::format($subscriptionsMonthly, $mainCurrencyCode) ?></span>
            <div class="title"><i class="fa-solid fa-credit-card"></i> <?= translate('subscriptions', $i18n) ?>
                (<?= $activeSubscriptions ?? 0 ?>)</div>
        </a>
        <a class="statistic" href="contracts.php">
            <span><?= CurrencyFormatter::format($contractsMonthly, $mainCurrencyCode) ?></span>
            <div class="title"><i class="fa-solid fa-file-signature"></i> <?= translate('contracts', $i18n) ?>
                (<?= $contractCount ?>)</div>
        </a>
        <a class="statistic" href="insurance.php">
            <span><?= CurrencyFormatter::format($insurancesMonthly, $mainCurrencyCode) ?></span>
            <div class="title"><i class="fa-solid fa-shield-halved"></i> <?= translate('insurances', $i18n) ?>
                (<?= $insuranceCount ?>)</div>
        </a>
        <a class="statistic" href="financing.php">
            <span><?= CurrencyFormatter::format($loansMonthly, $mainCurrencyCode) ?></span>
            <div class="title"><i class="fa-solid fa-landmark"></i> <?= translate('financing_rates', $i18n) ?></div>
        </a>
        <?php if ($consumptionMonthly > 0): ?>
            <a class="statistic" href="consumption.php">
                <span><?= CurrencyFormatter::format($consumptionMonthly, $mainCurrencyCode) ?></span>
                <div class="title"><i class="fa-solid fa-gauge"></i> <?= translate('consumption', $i18n) ?></div>
            </a>
        <?php endif; ?>
        <?php if ($loansRemaining > 0): ?>
            <a class="statistic" href="financing.php">
                <span><?= CurrencyFormatter::format($loansRemaining, $mainCurrencyCode) ?></span>
                <div class="title"><i class="fa-solid fa-scale-unbalanced"></i> <?= translate('remaining_debt', $i18n) ?>
                </div>
            </a>
        <?php endif; ?>
        <?php if ($pvMonthlyEarnings > 0): ?>
            <a class="statistic positive" href="consumption.php">
                <span><?= CurrencyFormatter::format($pvMonthlyEarnings, $mainCurrencyCode) ?></span>
                <div class="title"><i class="fa-solid fa-solar-panel"></i> <?= translate('pv_earnings_monthly', $i18n) ?>
                </div>
            </a>
        <?php endif; ?>
    </div>

    <?php
    // If there are overdue subscriptions, display them
    if ($hasOverdueSubscriptions) {
        ?>
        <div class="overdue-subscriptions">
            <h2><?= translate('overdue_renewals', $i18n) ?></h2>
            <div class="dashboard-subscriptions-container">
                <div class="dashboard-subscriptions-list">
                    <?php

                    foreach ($overdueSubscriptions as $subscription) {
                        $subscriptionName = htmlSafe($subscription['name']);
                        $subscriptionPrice = $subscription['price'];
                        $subscriptionCurrency = $subscription['currency_id'];
                        $subscriptionNextPayment = $subscription['next_payment'];
                        $subscriptionDisplayNextPayment = formatDate($subscriptionNextPayment, $lang);
                        $subscriptionDisplayPrice = formatPrice($subscriptionPrice, $currencies[$subscriptionCurrency]['code'], $currencies);

                        ?>
                        <div class="subscription-item" onClick="showSubscriptionDetails(event, <?= $subscription['id'] ?>)" data-id="<?= $subscription['id'] ?>">
                            <?php
                            if (empty($subscription['logo'])) {
                                ?>
                                <p class="subscription-item-title"><?= $subscriptionName ?></p>
                                <?php
                            } else {
                                $subscriptionLogoSrc = "images/uploads/logos/" . $subscription['logo'];
                                $subscriptionLogoVariantSrc = !empty($subscription['logo_variant']) ? "images/uploads/logos/" . $subscription['logo_variant'] : null;
                                echo renderThemedLogoImg($subscriptionLogoSrc, $subscriptionLogoVariantSrc, $subscription['logo_text_color'] ?? null, 'subscription-item-logo', 'alt="' . $subscriptionName . ' logo" title="' . $subscriptionName . '"');
                            }
                            ?>
                            <div class="subscription-item-info">
                                <p class="subscription-item-date"> <?= $subscriptionDisplayNextPayment ?>
                                </p>
                                <p class="subscription-item-price"> <?= $subscriptionDisplayPrice ?></p>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
    ?>

    <div class="dashboard-grid">
        <div class="dashboard-panel">
            <h2><?= translate('upcoming_events', $i18n) ?></h2>
            <?php if (empty($upcomingEvents)): ?>
                <p class="dashboard-empty"><?= translate('no_upcoming_payments', $i18n) ?></p>
            <?php else: ?>
                <ul class="upcoming-events">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <li class="upcoming-event <?= $event['class'] ?>">
                            <a href="<?= $event['link'] ?>">
                                <span class="upcoming-event-date"><?= formatDate($event['date'], $lang) ?></span>
                                <span class="upcoming-event-icon"><i class="fa-solid <?= $event['icon'] ?>"></i></span>
                                <span class="upcoming-event-name"><?= $event['name'] ?></span>
                                <span class="upcoming-event-detail"><?= $event['detail'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($showFirstSteps): ?>
            <div class="dashboard-panel">
                <h2><i class="fa-solid fa-flag-checkered"></i> <?= translate('first_steps', $i18n) ?></h2>
                <ul class="savings-list">
                    <?php foreach ($firstSteps as [$key, $link, $done]): ?>
                        <li><a href="<?= $link ?>"><i class="fa-<?= $done ? 'solid fa-circle-check' : 'regular fa-circle' ?>"></i> <?= translate('first_step_' . $key, $i18n) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($householdHints)): ?>
            <div class="dashboard-panel">
                <h2><i class="fa-solid fa-lightbulb"></i> <?= translate('household_hints', $i18n) ?></h2>
                <ul class="savings-list">
                    <?php foreach ($householdHints as [$icon, $text, $link]): ?>
                        <li><a href="<?= $link ?>"><i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($text) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($priceIncreases)): ?>
            <div class="dashboard-panel">
                <h2><i class="fa-solid fa-arrow-trend-up"></i> <?= translate('price_increases', $i18n) ?></h2>
                <ul class="upcoming-events price-increases">
                    <?php foreach ($priceIncreases as $pi):
                        $code = $currencies[$pi['currency_id']]['code'] ?? $mainCurrencyCode;
                        $pct = $pi['old_price'] > 0 ? ($pi['new_price'] / $pi['old_price'] - 1) * 100 : 0;
                        $link = ['subscription' => 'subscriptions.php', 'contract' => 'contracts.php', 'insurance' => 'insurance.php'][$pi['item_type']];
                        ?>
                        <li class="upcoming-event warning">
                            <a href="<?= $link ?>">
                                <span class="upcoming-event-date"><?= formatDate($pi['changed_at'], $lang) ?></span>
                                <span class="upcoming-event-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                                <span class="upcoming-event-name"><?= htmlspecialchars($pi['name']) ?></span>
                                <span class="upcoming-event-detail">
                                    <?= formatPrice($pi['old_price'], $code, $currencies) ?> → <?= formatPrice($pi['new_price'], $code, $currencies) ?>
                                    (+<?= number_format($pct, 1, ',', '.') ?> %)
                                    <?php if ($pi['item_type'] !== 'subscription'): ?>
                                        · <?= translate('special_termination_hint', $i18n) ?>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (count($burdenSplitDataPoints) > 1): ?>
            <div class="dashboard-panel">
                <h2><?= translate('monthly_burden_split', $i18n) ?></h2>
                <div class="dashboard-chart-wrapper">
                    <div id="burdenSplitChart" class="apex-chart"></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($loanProgress) > 0): ?>
            <div class="dashboard-panel">
                <h2><?= translate('financing_progress', $i18n) ?></h2>
                <?php foreach ($loanProgress as $loan): ?>
                    <a class="dashboard-loan" href="financing.php?id=<?= $loan['id'] ?>">
                        <div class="dashboard-loan-header">
                            <span><?= $loan['name'] ?></span>
                            <span><?= CurrencyFormatter::format($loan['remaining'], $loan['currency_code']) ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $loan['percent'] ?>%"></div>
                        </div>
                        <div class="dashboard-loan-footer">
                            <span><?= $loan['percent'] ?> % <?= translate('repaid', $i18n) ?></span>
                            <span><?= translate('payoff_date', $i18n) ?>: <?= date('m/Y', strtotime($loan['payoff'])) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($meterMonthCompare): ?>
            <div class="dashboard-panel">
                <h2><i class="fa-solid fa-gauge"></i> <?= sprintf(translate('consumption_last_month', $i18n), date('m/Y', strtotime($meterMonthCompare[0]['month'] . '-01'))) ?></h2>
                <ul class="meter-compare-list">
                    <?php foreach ($meterMonthCompare as $cmp):
                        // More consumption is bad, more feed-in is good.
                        $better = $cmp['change'] !== null && ($cmp['is_pv'] ? $cmp['change'] > 0 : $cmp['change'] < 0); ?>
                        <li>
                            <i class="fa-solid <?= $cmp['icon'] ?>" aria-hidden="true"></i>
                            <span class="meter-compare-name"><?= $cmp['name'] ?></span>
                            <strong><?= number_format($cmp['units'], 0, ',', '.') ?> <?= $cmp['unit'] ?></strong>
                            <?php if ($cmp['change'] !== null): ?>
                                <span class="meter-compare-change <?= $better ? 'positive' : (round($cmp['change']) == 0 ? '' : 'negative') ?>"
                                    title="<?= translate('compared_to_last_year', $i18n) ?>">
                                    <?php $rounded = (int) round($cmp['change']); ?><?= $rounded > 0 ? '+' : ($rounded < 0 ? '−' : '±') ?><?= abs($rounded) ?> %
                                </span>
                            <?php else: ?>
                                <span class="meter-compare-change">—</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="dashboard-panel-link" href="consumption.php"><?= translate('show_details', $i18n) ?> <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        <?php endif; ?>

        <?php foreach ($meterSnapshots as $snapshot): ?>
            <?php if (count($snapshot['monthly']) > 0): ?>
                <div class="dashboard-panel">
                    <h2><i class="fa-solid <?= $snapshot['icon'] ?>"></i> <?= $snapshot['name'] ?></h2>
                    <div class="dashboard-chart-wrapper small">
                        <div id="dashboardMeterChart-<?= $snapshot['id'] ?>" class="apex-chart"></div>
                    </div>
                    <?php if ($snapshot['forecast'] !== null): ?>
                        <p class="dashboard-meter-forecast <?= $snapshot['is_pv'] ? 'positive' : '' ?>">
                            <i class="fa-solid fa-chart-line"></i>
                            <?= translate('forecast_until_year_end', $i18n) ?>:
                            +<?= number_format($snapshot['forecast']['projected_units'], 0, ',', '.') ?>         <?= $snapshot['unit'] ?>
                        </p>
                    <?php endif; ?>
                    <a class="dashboard-panel-link" href="consumption.php"><?= translate('show_details', $i18n) ?> <i
                            class="fa-solid fa-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($hasUpcomingCancellations) { ?>
            <div class="cancellation-subscriptions">
                <h2><?= translate('upcoming_cancellations', $i18n) ?></h2>
                <div class="dashboard-subscriptions-container">
                    <div class="dashboard-subscriptions-list">
                        <?php
                        foreach ($upcomingCancellations as $subscription) {
                            $subscriptionName = htmlSafe($subscription['name']);
                            $subscriptionPrice = $subscription['price'];
                            $subscriptionCurrency = $subscription['currency_id'];
                            $subscriptionDisplayCancellationDate = formatDate($subscription['cancellation_date'], $lang);
                            $subscriptionDisplayPrice = formatPrice($subscriptionPrice, $currencies[$subscriptionCurrency]['code'], $currencies);

                            ?>
                            <div class="subscription-item" onClick="showSubscriptionDetails(event, <?= $subscription['id'] ?>)" data-id="<?= $subscription['id'] ?>">
                                <?php
                                if (empty($subscription['logo'])) {
                                    ?>
                                    <p class="subscription-item-title"><?= $subscriptionName ?></p>
                                    <?php
                                } else {
                                    $subscriptionLogoSrc = "images/uploads/logos/" . $subscription['logo'];
                                    $subscriptionLogoVariantSrc = !empty($subscription['logo_variant']) ? "images/uploads/logos/" . $subscription['logo_variant'] : null;
                                    echo renderThemedLogoImg($subscriptionLogoSrc, $subscriptionLogoVariantSrc, $subscription['logo_text_color'] ?? null, 'subscription-item-logo', 'alt="' . $subscriptionName . ' logo" title="' . $subscriptionName . '"');
                                }
                                ?>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-date"> <?= $subscriptionDisplayCancellationDate ?></p>
                                    <p class="subscription-item-price"> <?= $subscriptionDisplayPrice ?></p>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php if (!empty($aiRecommendations)) { ?>
            <div class="dashboard-panel">
                <h2><?= translate('ai_recommendations', $i18n) ?></h2>
                <div class="ai-recommendations-container">
                    <ul class="ai-recommendations-list">
                        <?php

                        foreach ($aiRecommendations as $key => $recommendation) { ?>
                            <li class="ai-recommendation-item" data-id="<?= $recommendation['id'] ?>">
                                <div class="ai-recommendation-header">
                                    <h3>
                                        <span><?= ($key + 1) . ". " ?></span>
                                        <?= htmlspecialchars($recommendation['title']) ?>
                                    </h3>
                                    <span class="item-arrow-down fa fa-caret-down"></span>
                                </div>
                                <p class="collapsible"><?= htmlspecialchars($recommendation['description']) ?></p>
                                <p class="ai-recommendation-savings">
                                    <?= htmlspecialchars($recommendation['savings']) ?>
                                    <span>
                                        <a href="#" class="delete-ai-recommendation" title="<?= translate('delete', $i18n) ?>">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </span>
                                </p>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($totalCostPerMonth)) { ?>
            <div class="dashboard-panel">
                <h2><?= translate('monthly_budget', $i18n) ?></h2>
                <div>
                    <div class="dashboard-subscriptions-list">
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate("monthly_cost", $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($totalCostPerMonth, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                        </div>
                        <?php if (isset($monthlyBudget) && $monthlyBudget > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($monthlyBudget, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (isset($monthlyBudgetUsed)) { ?>
                                <div class="subscription-item thin">
                                    <p class="subscription-item-title"><?= translate("budget_used", $i18n) ?></p>
                                    <div class="subscription-item-info">
                                        <p class="subscription-item-value">
                                            <?= number_format($monthlyBudgetUsed, 2) ?>%
                                        </p>
                                    </div>
                                </div>
                            <?php } ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("budget_remaining", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($monthlyBudgetLeft, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (isset($monthlyOverBudgetAmount) && $monthlyOverBudgetAmount > 0) { ?>
                                <div class="subscription-item thin">
                                    <p class="subscription-item-title"><?= translate("over_budget", $i18n) ?></p>
                                    <div class="subscription-item-info">
                                        <p class="subscription-item-value">
                                            <?= formatPrice($monthlyOverBudgetAmount, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php
        // The period block is about the period, not about the budget: a user who
        // aligns the period to their payslip wants to see what is left to pay
        // whether or not they also set an amount to compare it against. The
        // second arm keeps the block for anyone who had a period budget before
        // the opt-in existed.
        if ($periodScopesStatistics || (isset($periodBudget) && $periodBudget > 0)) { ?>
            <div class="dashboard-panel">
                <h2><?= translate(isset($periodBudget) && $periodBudget > 0 ? 'period_budget' : 'payment_period', $i18n) ?></h2>
                <?php if (isset($budgetPeriodLabel)) { ?>
                    <p class="header-subtitle"><?= translate('current_period', $i18n) ?>: <?= htmlspecialchars($budgetPeriodLabel, ENT_QUOTES, 'UTF-8') ?></p>
                <?php } ?>
                <div>
                    <div class="dashboard-subscriptions-list">
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate("amount_needed_this_period", $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= CurrencyFormatter::format($amountNeededThisPeriod, $currencies[$userData['main_currency']]['code']) ?>
                                </p>
                            </div>
                        </div>
                        <?php if (isset($periodBudget) && $periodBudget > 0) { ?>
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate("budget", $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= formatPrice($periodBudget, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                </p>
                            </div>
                        </div>
                        <?php } ?>
                        <?php if (isset($periodBudgetUsed)) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("budget_used", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= number_format($periodBudgetUsed, 2) ?>%
                                    </p>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if (isset($periodBudgetLeft)) { ?>
                        <div class="subscription-item thin">
                            <p class="subscription-item-title"><?= translate("budget_remaining", $i18n) ?></p>
                            <div class="subscription-item-info">
                                <p class="subscription-item-value">
                                    <?= formatPrice($periodBudgetLeft, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                </p>
                            </div>
                        </div>
                        <?php } ?>
                        <?php if (isset($periodOverBudgetAmount) && $periodOverBudgetAmount > 0) { ?>
                            <div class="subscription-item thin">
                                <p class="subscription-item-title"><?= translate("over_budget", $i18n) ?></p>
                                <div class="subscription-item-info">
                                    <p class="subscription-item-value">
                                        <?= formatPrice($periodOverBudgetAmount, $currencies[$userData['main_currency']]['code'], $currencies) ?>
                                    </p>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>

        <?php if (isset($inactiveSubscriptions) && $inactiveSubscriptions > 0 && isset($totalSavingsPerMonth) && $totalSavingsPerMonth > 0) { ?>
            <div class="dashboard-panel">
                <h2><?= translate('your_savings', $i18n) ?></h2>
                <div class="dashboard-subscriptions-list">
                    <div class="subscription-item thin">
                        <p class="subscription-item-title"><?= translate('inactive_subscriptions', $i18n) ?></p>
                        <div class="subscription-item-info">
                            <p class="subscription-item-value"><?= $inactiveSubscriptions ?></p>
                        </div>
                    </div>
                    <div class="subscription-item thin">
                        <p class="subscription-item-title"><?= translate('monthly_savings', $i18n) ?></p>
                        <div class="subscription-item-info">
                            <p class="subscription-item-value">
                                <?= CurrencyFormatter::format($totalSavingsPerMonth, $mainCurrencyCode) ?>
                            </p>
                        </div>
                    </div>
                    <div class="subscription-item thin">
                        <p class="subscription-item-title"><?= translate('yearly_savings', $i18n) ?></p>
                        <div class="subscription-item-info">
                            <p class="subscription-item-value">
                                <?= CurrencyFormatter::format($totalSavingsPerMonth * 12, $mainCurrencyCode) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>

</section>

<script src="scripts/dashboard-search.js?<?= $assetVersion ?>"></script>
<script src="scripts/libs/apexcharts.min.js"></script>
<script src="scripts/apex-base.js?<?= $assetVersion ?>"></script>
<script type="text/javascript">
    window.dashboardData = {
        burdenSplit: <?= json_encode($burdenSplitDataPoints, JSON_NUMERIC_CHECK) ?>,
        currency: "<?= $mainCurrencyCode ?>",
        meters: <?php
        $dashboardMeters = [];
        foreach ($meterSnapshots as $snapshot) {
            if (count($snapshot['monthly']) > 0) {
                $dashboardMeters[] = [
                    'id' => $snapshot['id'],
                    'monthly' => $snapshot['monthly'],
                    'unit' => $snapshot['unit'],
                    'is_pv' => $snapshot['is_pv'],
                ];
            }
        }
        echo json_encode($dashboardMeters);
        ?>
    };
</script>
<?php
// Get all subscriptions for user details lookup
$query = 'SELECT * FROM subscriptions WHERE user_id = :userId';
$stmt = $db->prepare($query);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$subscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
}
require_once 'includes/subscription_details_popup.php';
?>

<script src="scripts/dashboard.js?<?= $assetVersion ?>"></script>

<?php
require_once 'includes/footer.php';
?>
