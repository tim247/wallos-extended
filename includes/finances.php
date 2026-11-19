<?php
// Budget planning: income, savings goals, planned variable expenses, the balance over the
// month, the changes ahead and the split per person (finances.php).
require_once __DIR__ . '/price_helpers.php';

/** @return array<int, array> current incomes with 'monthly' in the main currency (ended ones are left out) */
function financeIncomes(SQLite3 $db, int $userId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $stmt = $db->prepare('SELECT * FROM incomes WHERE user_id = :userId ORDER BY amount DESC');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($row['end_date']) && $row['end_date'] < $today) {
            continue;
        }
        $amount = getPriceConverted((float) $row['amount'], $row['currency_id'], $db, $userId);
        $row['monthly'] = round(getPricePerMonth((int) $row['cycle'], (int) $row['frequency'], $amount), 2);
        $out[] = $row;
    }
    return $out;
}

/**
 * Savings goals with the monthly amount still needed to reach the target in time.
 * @return array<int, array> rows plus 'progress' (0..1), 'months_left' and 'monthly_needed'
 */
function financeGoals(SQLite3 $db, int $userId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $stmt = $db->prepare('SELECT * FROM savings_goals WHERE user_id = :userId ORDER BY target_date IS NULL, target_date');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $missing = max(0, (float) $row['target_amount'] - (float) $row['saved_amount']);
        $monthsLeft = null;
        if (!empty($row['target_date'])) {
            $from = new DateTime($today);
            $to = new DateTime($row['target_date']);
            $diff = $from->diff($to);
            $monthsLeft = $to <= $from ? 0 : max(1, $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0));
        }
        $row['progress'] = $row['target_amount'] > 0 ? min(1, $row['saved_amount'] / $row['target_amount']) : 0;
        $row['months_left'] = $monthsLeft;
        $row['monthly_needed'] = $missing > 0 && $monthsLeft ? round($missing / $monthsLeft, 2) : ($missing > 0 && $monthsLeft === 0 ? $missing : 0);
        $out[] = $row;
    }
    return $out;
}

/** Planned variable expenses (groceries, fuel ...), per month in the main currency. */
function financeExpenses(SQLite3 $db, int $userId): array
{
    $stmt = $db->prepare('SELECT * FROM variable_expenses WHERE user_id = :userId ORDER BY amount DESC');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['monthly'] = round((float) $row['amount'], 2);
        $out[] = $row;
    }
    return $out;
}

/**
 * Budget lines: every kind of cost with its share of the income.
 * @return array<int, array{key: string, monthly: float, share: ?float}>
 */
function financeBreakdown(array $incomes, array $costItems, array $expenses, array $goals): array
{
    $income = array_sum(array_column($incomes, 'monthly'));
    $lines = [];
    foreach (['insurance', 'loan', 'contract', 'subscription', 'meter', 'vehicle'] as $type) {
        $lines[$type] = array_sum(array_column(array_filter($costItems, fn($item) => $item['type'] === $type), 'monthly'));
    }
    $lines['variable'] = array_sum(array_column($expenses, 'monthly'));
    $lines['savings'] = array_sum(array_column($goals, 'monthly_needed'));
    $out = [];
    foreach ($lines as $key => $monthly) {
        if ($monthly > 0) {
            $out[] = ['key' => $key, 'monthly' => round($monthly, 2), 'share' => $income > 0 ? $monthly / $income : null];
        }
    }
    return $out;
}

/**
 * Money in and out per day of one month, for the balance curve.
 * Income arrives on its pay day; costs on their due dates (like the calendar);
 * variable expenses and forecast-only meter costs are spread evenly over the days.
 * @return array<int, float> day of month => net amount (positive = in)
 */
function financeCashflow(SQLite3 $db, int $userId, array $incomes, array $costItems, array $expenses, int $year, int $month): array
{
    require_once __DIR__ . '/budget_period_calculations.php';
    require_once __DIR__ . '/financing_calculations.php';
    $from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $to = (clone $from)->modify('last day of this month');
    $fromKey = $from->format('Y-m-d');
    $toKey = $to->format('Y-m-d');
    $days = (int) $to->format('j');
    $flow = array_fill(1, $days, 0.0);
    $rows = function (string $sql) use ($db, $userId): array {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $out = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };
    $add = function (string $date, float $amount) use (&$flow, $fromKey, $toKey) {
        if ($date >= $fromKey && $date <= $toKey) {
            $flow[(int) substr($date, 8, 2)] += $amount;
        }
    };

    // ponytail: every income counts with its monthly share on its pay day, yearly ones too.
    foreach ($incomes as $income) {
        $flow[max(1, min($days, (int) ($income['pay_day'] ?: 1)))] += $income['monthly'];
    }
    foreach ($rows('SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0') as $sub) {
        foreach (getSubscriptionOccurrencesInRange($sub, $from, $to) as $date) {
            $add($date->format('Y-m-d'), -getPriceConverted((float) $sub['price'], $sub['currency_id'], $db, $userId));
        }
    }
    // Same walk as calendar.php: from the start date in steps of the billing cycle.
    foreach (['contracts', 'insurances'] as $table) {
        foreach ($rows("SELECT * FROM $table WHERE user_id = :userId AND archived = 0") as $row) {
            $unit = [1 => 'days', 2 => 'weeks', 3 => 'months', 4 => 'years'][(int) $row['cycle']] ?? null;
            if ($unit === null || empty($row['start_date'])) {
                continue;
            }
            $date = new DateTime($row['start_date']);
            $last = !empty($row['end_date']) ? min($row['end_date'], $toKey) : $toKey;
            for ($guard = 0; $date->format('Y-m-d') <= $last && $guard < 6000; $guard++) {
                $add($date->format('Y-m-d'), -getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId));
                $date->modify('+' . max(1, (int) $row['frequency']) . ' ' . $unit);
            }
        }
    }
    foreach ($rows('SELECT * FROM loans WHERE user_id = :userId AND archived = 0') as $loan) {
        foreach (loadLoanSchedule($db, $userId, $loan)['rows'] ?? [] as $scheduleRow) {
            $add($scheduleRow['date'], -getPriceConverted((float) ($scheduleRow['payment'] ?? 0), $loan['currency_id'], $db, $userId));
        }
    }
    foreach ($rows('SELECT * FROM meters WHERE user_id = :userId AND archived = 0 AND monthly_advance > 0') as $meter) {
        $day = !empty($meter['billing_start']) ? (int) date('j', strtotime($meter['billing_start'])) : 1;
        $flow[min($day, $days)] -= getPriceConverted((float) $meter['monthly_advance'], $meter['currency_id'], $db, $userId);
    }
    $spread = array_sum(array_column($expenses, 'monthly'))
        + array_sum(array_column(array_filter($costItems, fn($item) => !empty($item['estimate'])), 'monthly'));
    foreach ($flow as $day => $amount) {
        $flow[$day] = round($amount - $spread / $days, 2);
    }
    return $flow;
}

/**
 * Changes ahead: incomes that end, loans that are paid off, contracts and insurances that run out.
 * @return array<int, array{date: string, name: string, delta: float}> delta = change of the monthly free amount
 */
function financeOutlook(SQLite3 $db, int $userId, array $incomes, ?string $today = null): array
{
    require_once __DIR__ . '/financing_calculations.php';
    $today ??= date('Y-m-d');
    $events = [];
    foreach ($incomes as $income) {
        if (!empty($income['end_date']) && $income['end_date'] > $today) {
            $events[] = ['date' => $income['end_date'], 'name' => $income['name'], 'delta' => -$income['monthly']];
        }
    }
    $stmt = $db->prepare('SELECT * FROM loans WHERE user_id = :userId AND archived = 0');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $loans = [];
    while ($loan = $result->fetchArray(SQLITE3_ASSOC)) {
        $loans[] = $loan;
    }
    foreach ($loans as $loan) {
        $schedule = loadLoanSchedule($db, $userId, $loan, $today);
        if (!empty($schedule['payoff_date']) && $schedule['payoff_date'] > $today && $schedule['balance_as_of'] > 0) {
            $events[] = ['date' => $schedule['payoff_date'], 'name' => $loan['name'],
                'delta' => round(getPriceConverted((float) $loan['monthly_rate'], $loan['currency_id'], $db, $userId), 2)];
        }
    }
    foreach (['contracts', 'insurances'] as $table) {
        $stmt = $db->prepare("SELECT * FROM $table WHERE user_id = :userId AND archived = 0 AND auto_renews = 0 AND end_date > :today");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $today);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $amount = getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId);
            $events[] = ['date' => $row['end_date'], 'name' => $row['name'],
                'delta' => round(getPricePerMonth((int) $row['cycle'], (int) $row['frequency'], $amount), 2)];
        }
    }
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $events;
}

/**
 * Who earns what, who pays what, and the fair share of all costs by income.
 * @return array<int, array{name: string, income: float, pays: float, fair: float}>
 */
function financePerPerson(SQLite3 $db, int $userId, array $incomes, array $costItems, array $expenses): array
{
    $stmt = $db->prepare('SELECT id, name FROM household WHERE user_id = :userId ORDER BY id');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $people = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $people[(int) $row['id']] = ['name' => $row['name'], 'income' => 0.0, 'pays' => 0.0, 'fair' => 0.0];
    }
    foreach ($incomes as $income) {
        if (isset($people[(int) $income['payer_user_id']])) {
            $people[(int) $income['payer_user_id']]['income'] += $income['monthly'];
        }
    }
    $expenseItems = array_map(fn($e) => ['monthly' => $e['monthly'], 'payer_id' => (int) $e['payer_user_id']], $expenses);
    foreach (array_merge($costItems, $expenseItems) as $item) {
        $payer = (int) ($item['payer_id'] ?? 0);
        if (isset($people[$payer])) {
            $people[$payer]['pays'] += $item['monthly'];
        }
    }
    $totalIncome = array_sum(array_column($people, 'income'));
    $totalCosts = array_sum(array_column($costItems, 'monthly')) + array_sum(array_column($expenses, 'monthly'));
    foreach ($people as &$person) {
        $person['fair'] = $totalIncome > 0 ? round($totalCosts * $person['income'] / $totalIncome, 2) : 0.0;
    }
    unset($person);
    return array_values(array_filter($people, fn($p) => $p['income'] > 0 || $p['pays'] > 0));
}
