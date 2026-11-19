<?php
// Every active recurring cost of a user, converted to the main currency.
// Shared by the summary API (Home Assistant), the annual report and the bank import.

require_once __DIR__ . '/price_helpers.php';
require_once __DIR__ . '/financing_calculations.php';

/**
 * @return array<int, array{type: string, id: int, name: string, match: string, amount: float, monthly: float, payer_id: int, tax: int}>
 *   amount = one payment in the main currency, match = text to find it on a bank statement
 */
function householdCostItems(SQLite3 $db, int $userId): array
{
    $items = [];
    $rows = function (string $sql) use ($db, $userId): array {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $out = [];
        while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };

    $sources = [
        'subscription' => ["SELECT *, '' AS provider FROM subscriptions WHERE user_id = :userId AND archived = 0 AND inactive = 0", 'provider'],
        'contract' => ["SELECT * FROM contracts WHERE user_id = :userId AND archived = 0", 'provider'],
        'insurance' => ["SELECT *, insurer AS provider FROM insurances WHERE user_id = :userId AND archived = 0", 'provider'],
    ];
    foreach ($sources as $type => [$sql]) {
        foreach ($rows($sql) as $row) {
            $amount = getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId);
            $items[] = [
                'type' => $type,
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'match' => trim($row['name'] . ' ' . ($row['provider'] ?? '')),
                'amount' => round($amount, 2),
                'price' => (float) $row['price'], // in its own currency, like the price history
                'monthly' => round(getPricePerMonth((int) $row['cycle'], (int) $row['frequency'], $amount), 2),
                'payer_id' => (int) ($row['payer_user_id'] ?? 0),
                'tax' => (int) ($row['tax_deductible'] ?? 0),
                'category_id' => (int) ($row['category_id'] ?? 0),
            ];
        }
    }
    // Kfz-Steuer: yearly, counted with a twelfth per month; the plate finds the Bundeskasse debit on the statement.
    $taxLabel = function_exists('translate') ? translate('vehicle_tax', $GLOBALS['i18n'] ?? []) : 'Kfz-Steuer';
    foreach ($rows('SELECT * FROM vehicles WHERE user_id = :userId AND tax_amount > 0') as $row) {
        $items[] = ['type' => 'vehicle', 'id' => (int) $row['id'], 'name' => $taxLabel . ' · ' . $row['name'],
            'match' => trim($row['plate'] . ' ' . $taxLabel), 'amount' => (float) $row['tax_amount'],
            'price' => (float) $row['tax_amount'], 'monthly' => round((float) $row['tax_amount'] / 12, 2), 'payer_id' => 0, 'tax' => 0];
    }
    foreach ($rows("SELECT * FROM loans WHERE user_id = :userId AND archived = 0") as $row) {
        $schedule = loadLoanSchedule($db, $userId, $row, date('Y-m-d'));
        if ($schedule['error'] !== null || $schedule['balance_as_of'] <= 0) {
            continue; // paid off
        }
        $amount = round(getPriceConverted((float) $row['monthly_rate'], $row['currency_id'], $db, $userId), 2);
        $items[] = ['type' => 'loan', 'id' => (int) $row['id'], 'name' => $row['name'], 'match' => trim($row['name'] . ' ' . $row['lender']),
            'amount' => $amount, 'monthly' => $amount, 'payer_id' => 0, 'tax' => 0];
    }
    // Meters: the advance payment (Abschlag) when there is one, else the forecast cost like the
    // dashboard. Forecasts are flagged 'estimate': there is no fixed debit to find on a bank statement.
    require_once __DIR__ . '/consumption_calculations.php';
    foreach ($rows("SELECT * FROM meters WHERE user_id = :userId AND archived = 0 AND meter_type NOT IN ('pv_feed_in', 'pv_generation')") as $row) {
        if ((float) $row['monthly_advance'] > 0) {
            $amount = round(getPriceConverted((float) $row['monthly_advance'], $row['currency_id'], $db, $userId), 2);
            $items[] = ['type' => 'meter', 'id' => (int) $row['id'], 'name' => $row['name'], 'match' => $row['name'],
                'amount' => $amount, 'monthly' => $amount, 'payer_id' => 0, 'tax' => 0];
            continue;
        }
        $readings = $rows('SELECT reading_date, reading_value, is_meter_change FROM meter_readings WHERE meter_id = ' . (int) $row['id'] . ' AND user_id = :userId ORDER BY reading_date');
        $forecast = computeForecast(computeConsumptionSegments($readings)['segments'], date('Y') . '-12-31');
        if ($forecast === null || $forecast['per_day'] <= 0) {
            continue;
        }
        $conversion = $row['meter_type'] === 'gas' && !empty($row['conversion_factor']) ? (float) $row['conversion_factor'] : 1.0;
        $amount = round(getPriceConverted($forecast['per_day'] * 30.44 * $conversion * (float) $row['price_per_unit'] + (float) $row['base_fee_monthly'], $row['currency_id'], $db, $userId), 2);
        $items[] = ['type' => 'meter', 'id' => (int) $row['id'], 'name' => $row['name'], 'match' => $row['name'],
            'amount' => $amount, 'monthly' => $amount, 'payer_id' => 0, 'tax' => 0, 'estimate' => true];
    }
    return $items;
}

/**
 * Categories with a monthly budget and what their subscriptions and contracts cost.
 * @return array<int, array{name: string, budget: float, spent: float}>
 */
function categoryBudgets(SQLite3 $db, int $userId, array $items): array
{
    $spent = [];
    foreach ($items as $item) {
        if (!empty($item['category_id'])) {
            $spent[$item['category_id']] = ($spent[$item['category_id']] ?? 0) + $item['monthly'];
        }
    }
    $stmt = $db->prepare('SELECT id, name, budget FROM categories WHERE user_id = :userId AND budget > 0 ORDER BY `order`');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $out[] = ['name' => $row['name'], 'budget' => (float) $row['budget'], 'spent' => round($spent[$row['id']] ?? 0, 2)];
    }
    return $out;
}

/**
 * Basic insurances a household usually needs but has not recorded.
 * Wohngebäude only when a loan suggests owned property.
 * @return string[] insurance_type keys
 */
function missingInsurances(SQLite3 $db, int $userId): array
{
    $stmt = $db->prepare('SELECT DISTINCT insurance_type FROM insurances WHERE user_id = :userId AND archived = 0');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $have = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $have[] = $row['insurance_type'];
    }
    if ($have === []) {
        return []; // insurances not tracked here at all
    }
    $needed = ['liability', 'household'];
    $stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE user_id = :userId AND archived = 0 AND COALESCE(loan_type, 'annuity') != 'bausparen'");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if ($stmt->execute()->fetchArray()[0] > 0) {
        $needed[] = 'building';
    }
    return array_values(array_diff($needed, $have));
}
