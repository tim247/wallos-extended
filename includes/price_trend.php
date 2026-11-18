<?php
// Price trend of subscriptions, contracts and insurances against inflation, and the
// fixed-cost ratio (fixed costs against income) with a traffic light. Used by finances.php.

/**
 * German consumer price index (Destatis VPI, 2020 = 100), yearly average.
 * ponytail: fixed table, add a row each January; years after the last row continue at 2 %.
 */
const CONSUMER_PRICE_INDEX = [2015 => 94.5, 2016 => 95.0, 2017 => 96.4, 2018 => 98.1, 2019 => 99.5, 2020 => 100.0,
    2021 => 103.1, 2022 => 110.2, 2023 => 116.7, 2024 => 119.3, 2025 => 121.9];

function consumerPriceIndex(int $year): float
{
    $first = array_key_first(CONSUMER_PRICE_INDEX);
    $last = array_key_last(CONSUMER_PRICE_INDEX);
    if ($year <= $first) {
        return CONSUMER_PRICE_INDEX[$first];
    }
    return $year <= $last ? CONSUMER_PRICE_INDEX[$year] : CONSUMER_PRICE_INDEX[$last] * 1.02 ** ($year - $last);
}

/** Inflation in percent between two dates (yearly averages of the index). */
function inflationBetween(string $from, string $to): float
{
    return round((consumerPriceIndex((int) substr($to, 0, 4)) / consumerPriceIndex((int) substr($from, 0, 4)) - 1) * 100, 1);
}

/**
 * Every item whose price changed: first and last price from the price history, the change
 * in percent and the inflation over the same time. Totals over all cost items: what they
 * cost per month at their first known price and now.
 * @param array $history price_history rows (item_type, item_id, old_price, new_price, changed_at), oldest first
 * @param array $costItems householdCostItems()
 * @return array{items: array, then: float, now: float, change: ?float, inflation: ?float, since: ?string}
 */
function priceTrend(array $history, array $costItems, string $today): array
{
    $byItem = [];
    foreach ($history as $row) {
        $key = $row['item_type'] . ':' . $row['item_id'];
        $byItem[$key] ??= ['first' => (float) $row['old_price'], 'since' => $row['changed_at']];
        $byItem[$key]['last'] = (float) $row['new_price'];
    }
    $items = [];
    $then = $now = 0.0;
    $since = null;
    foreach ($costItems as $item) {
        $trend = $byItem[$item['type'] . ':' . $item['id']] ?? null;
        // Against the current price: a backdated entry alone (no change recorded since) still counts.
        $ratio = $trend && $trend['first'] > 0 ? ($item['price'] ?? $trend['last']) / $trend['first'] : 1.0;
        $now += $item['monthly'];
        $then += $item['monthly'] / $ratio;
        if ($trend && $ratio != 1.0) {
            $items[] = ['name' => $item['name'], 'type' => $item['type'], 'since' => $trend['since'],
                'then' => round($item['monthly'] / $ratio, 2), 'now' => $item['monthly'],
                'change' => round(($ratio - 1) * 100, 1), 'inflation' => inflationBetween($trend['since'], $today)];
            $since = $since === null || $trend['since'] < $since ? $trend['since'] : $since;
        }
    }
    usort($items, fn($a, $b) => $b['change'] <=> $a['change']);
    return ['items' => $items, 'then' => round($then, 2), 'now' => round($now, 2),
        'change' => $then > 0 && $items ? round(($now / $then - 1) * 100, 1) : null,
        'inflation' => $since ? inflationBetween($since, $today) : null, 'since' => $since];
}

/**
 * Fixed costs as a share of income with a traffic light: up to 50 % fine (rule of thumb
 * 50/30/20), up to 70 % tight, above that critical. Null without income.
 * @return array{share: float, light: string}|null
 */
function fixedCostRatio(float $fixedMonthly, float $incomeMonthly): ?array
{
    if ($incomeMonthly <= 0) {
        return null;
    }
    $share = round($fixedMonthly / $incomeMonthly * 100, 1);
    return ['share' => $share, 'light' => $share <= 50 ? 'green' : ($share <= 70 ? 'yellow' : 'red')];
}
