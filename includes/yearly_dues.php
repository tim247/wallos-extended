<?php
// Payments that are not due every month (yearly insurance, car tax, half-yearly
// contracts, yearly subscriptions ...) for the next 12 months, and a monthly
// reserve that covers them.

require_once __DIR__ . '/price_helpers.php';
require_once __DIR__ . '/cancellation_helpers.php';

const DUE_UNITS = [1 => 'days', 2 => 'weeks', 3 => 'months', 4 => 'years'];

/** Payment interval in months; null for one-time payments. */
function dueIntervalMonths(int $cycle, int $frequency): ?float
{
    $frequency = max(1, $frequency);
    return match ($cycle) {
        1 => $frequency / 30.44,
        2 => $frequency * 7 / 30.44,
        3 => (float) $frequency,
        4 => 12.0 * $frequency,
        default => null,
    };
}

/** Due dates in [$from, $to), only before $until (exclusive) when given. */
function dueOccurrences(string $anchor, int $cycle, int $frequency, string $from, string $to, ?string $until = null): array
{
    $unit = DUE_UNITS[$cycle] ?? null;
    $dates = [];
    if ($unit === null) {
        if ($anchor >= $from && $anchor < $to && ($until === null || $anchor < $until)) {
            $dates[] = $anchor;
        }
        return $dates;
    }
    $step = max(1, $frequency);
    $date = new DateTime($anchor);
    for ($guard = 0; $date->format('Y-m-d') < $from && $guard < 6000; $guard++) {
        $date->modify("+$step $unit");
    }
    for ($guard = 0; $date->format('Y-m-d') < $to && $guard < 6000; $guard++) {
        $day = $date->format('Y-m-d');
        if ($until !== null && $day >= $until) {
            break;
        }
        $dates[] = $day;
        $date->modify("+$step $unit");
    }
    return $dates;
}

function dueCycleLabel(int $cycle, int $frequency, array $i18n): string
{
    $units = [3 => ['Monthly', 'months'], 4 => ['Yearly', 'years'], 2 => ['Weekly', 'weeks'], 1 => ['Daily', 'days']];
    if ($cycle === 5 || !isset($units[$cycle])) {
        return translate('One-time', $i18n);
    }
    return $frequency == 1 ? translate($units[$cycle][0], $i18n) : $frequency . ' ' . translate($units[$cycle][1], $i18n);
}

/**
 * Minimal constant monthly reserve so that every due payment is covered when
 * saving starts now with $start already set aside (deposit, then payment).
 * @return array{monthly: float, balances: float[]} balance after each month
 */
function yearlyReservePlan(array $totals, float $start = 0.0): array
{
    $cumulative = 0.0;
    $monthly = 0.0;
    foreach ($totals as $i => $total) {
        $cumulative += $total;
        $monthly = max($monthly, ($cumulative - $start) / ($i + 1));
    }
    $balances = [];
    $cumulative = 0.0;
    foreach ($totals as $i => $total) {
        $cumulative += $total;
        $balances[] = round($start + ($i + 1) * $monthly - $cumulative, 2);
    }
    return ['monthly' => round($monthly, 2), 'balances' => $balances];
}

/**
 * @return array{items: array, months: array} items sorted by monthly share, months = 12 buckets from $from
 */
function collectYearlyDues(SQLite3 $db, int $userId, string $from, array $i18n): array
{
    $to = date('Y-m-d', strtotime($from . ' +12 months'));
    $items = [];

    $add = function (string $type, array $row, array $dates, ?string $until, string $link) use (&$items, $db, $userId, $from, $to, $i18n) {
        $interval = dueIntervalMonths((int) $row['cycle'], (int) $row['frequency']);
        if ($dates === [] || ($interval !== null && $interval < 2)) {
            return; // not due, or due every month anyway
        }
        $price = getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId);
        $items[] = [
            'type' => $type,
            'name' => $row['name'],
            'price' => $price,
            'cycle_label' => dueCycleLabel((int) $row['cycle'], (int) $row['frequency'], $i18n),
            'dates' => $dates,
            'share' => $price / ($interval ?? 12.0),
            'link' => $link,
        ];
    };

    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $renews = !empty($row['auto_renew']) && (int) $row['cycle'] !== 5;
        $cycle = $renews ? (int) $row['cycle'] : 5;
        $row['cycle'] = $cycle;
        $add('subscription', $row, dueOccurrences($row['next_payment'], $cycle, (int) $row['frequency'], $from, $to), null, 'subscriptions.php');
    }

    foreach (['contract' => ['contracts', 'contracts.php'], 'insurance' => ['insurances', 'insurance.php']] as $type => [$table, $link]) {
        $stmt = $db->prepare("SELECT * FROM $table WHERE user_id = :userId AND archived = 0");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (empty($row['start_date'])) {
                continue;
            }
            // Payments stop at the end date, or at the end of the term once cancelled.
            $until = (empty($row['auto_renews']) && !empty($row['end_date'])) ? $row['end_date'] : null;
            if (!empty($row['cancellation_status'])) {
                $deadline = getNextCancellationDeadline($row, $from);
                if ($deadline !== null && ($until === null || $deadline['term_end'] < $until)) {
                    $until = $deadline['term_end'];
                }
            }
            $add($type, $row, dueOccurrences($row['start_date'], (int) $row['cycle'], (int) $row['frequency'], $from, $to, $until), $until, $link);
        }
    }

    // Twelve month buckets, starting with the month of $from.
    $months = [];
    $fromTime = strtotime($from);
    for ($i = 0; $i < 12; $i++) {
        $time = strtotime("+$i months", $fromTime);
        $months[$i] = ['year' => (int) date('Y', $time), 'month' => (int) date('n', $time), 'total' => 0.0, 'entries' => []];
    }
    foreach ($items as $item) {
        foreach ($item['dates'] as $date) {
            $index = ((int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2)) - ((int) date('Y', $fromTime) * 12 + (int) date('n', $fromTime));
            if (!isset($months[$index])) {
                continue;
            }
            $months[$index]['total'] += $item['price'];
            $months[$index]['entries'][] = ['name' => $item['name'], 'amount' => $item['price'], 'date' => $date, 'type' => $item['type'], 'link' => $item['link']];
        }
    }
    foreach ($months as &$month) {
        usort($month['entries'], fn($a, $b) => strcmp($a['date'], $b['date']));
    }
    unset($month);

    usort($items, fn($a, $b) => $b['share'] <=> $a['share']);
    return ['items' => $items, 'months' => $months];
}
