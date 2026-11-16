<?php
// Consumption insights on top of consumption_calculations.php: prices by date,
// costs, year comparison, outliers, household benchmark, degree days and PV figures.
// Pure functions where possible; the few database / network helpers are marked.

require_once __DIR__ . '/consumption_calculations.php';

/** Gas meters are always heating; other meters when flagged (heat pump, night storage). */
function meterIsHeating(array $meter): bool
{
    return $meter['meter_type'] === 'gas' || !empty($meter['is_heating']);
}

/** kWh per m³ for gas, 1 for everything else. */
function meterConversion(array $meter): float
{
    return ($meter['meter_type'] === 'gas' && !empty($meter['conversion_factor'])) ? (float) $meter['conversion_factor'] : 1.0;
}

/**
 * All prices of a meter, oldest first: the stored earlier tariffs plus the current
 * price of the meter (valid from price_valid_from, or from the beginning).
 */
function meterTariffList(array $meter, array $earlierTariffs): array
{
    $list = [];
    foreach ($earlierTariffs as $t) {
        $list[] = ['id' => (int) ($t['id'] ?? 0), 'valid_from' => $t['valid_from'], 'price' => (float) $t['price_per_unit'], 'fee' => (float) $t['base_fee_monthly']];
    }
    $list[] = ['id' => null, 'valid_from' => $meter['price_valid_from'] ?: '0000-01-01', 'price' => (float) $meter['price_per_unit'], 'fee' => (float) $meter['base_fee_monthly']];
    usort($list, fn($a, $b) => strcmp($a['valid_from'], $b['valid_from']));
    return $list;
}

/** The price that applies on a date; before the first known date the oldest price. */
function tariffAt(array $tariffs, string $date): array
{
    $found = $tariffs[0];
    foreach ($tariffs as $t) {
        if ($t['valid_from'] <= $date) {
            $found = $t;
        }
    }
    return $found;
}

/**
 * Cost of a time window with the price valid on each day: work price for the measured
 * units, base fee per month. PV feed-in has no base fee (it is income).
 *
 * @return array{units: float, work: float, base: float, total: float}
 */
function costInWindow(array $segments, array $tariffs, string $from, string $to, float $conversion, bool $withBaseFee = true): array
{
    // Tariff periods inside the window.
    $bounds = [$from];
    foreach ($tariffs as $t) {
        if ($t['valid_from'] > $from && $t['valid_from'] < $to) {
            $bounds[] = $t['valid_from'];
        }
    }
    $bounds[] = $to;
    $units = $work = $base = 0.0;
    for ($i = 0; $i < count($bounds) - 1; $i++) {
        $tariff = tariffAt($tariffs, $bounds[$i]);
        $u = computeUnitsInWindow($segments, $bounds[$i], $bounds[$i + 1]);
        $units += $u;
        $work += $u * $conversion * $tariff['price'];
        if ($withBaseFee) {
            $base += $tariff['fee'] * ((strtotime($bounds[$i + 1]) - strtotime($bounds[$i])) / 86400 / 30.44);
        }
    }
    return ['units' => round($units, 2), 'work' => round($work, 2), 'base' => round($base, 2), 'total' => round($work + $base, 2)];
}

/**
 * Monthly consumption grouped by year for the year comparison chart.
 * @return array<int, array<int, float|null>> [2025 => [1 => 120.5, ..., 12 => null], ...]
 */
function yearComparison(array $monthly): array
{
    $years = [];
    foreach ($monthly as $month => $units) {
        [$y, $m] = array_map('intval', explode('-', $month));
        $years[$y] = $years[$y] ?? array_fill(1, 12, null);
        $years[$y][$m] = $units;
    }
    ksort($years);
    return $years;
}

/**
 * Unusually high consumption: the latest period against the same weeks one year earlier
 * when the readings reach back that far (heating rises every autumn - that is no leak),
 * otherwise against the 90 days before it. Only periods of up to 60 days count.
 *
 * @return array{from: string, to: string, per_day: float, usual: float, ratio: float}|null
 */
function detectOutlier(array $segments, float $threshold = 1.4): ?array
{
    if (count($segments) < 2) {
        return null;
    }
    $last = end($segments);
    if ($last['days'] > 60 || $last['delta'] <= 0) {
        return null;
    }
    $earlier = array_slice($segments, 0, -1);
    $yearAgoFrom = date('Y-m-d', strtotime($last['from'] . ' -1 year'));
    $yearAgoTo = date('Y-m-d', strtotime($last['to'] . ' -1 year'));
    if ($segments[0]['from'] <= $yearAgoFrom) {
        $usualUnits = computeUnitsInWindow($earlier, $yearAgoFrom, $yearAgoTo);
        $usualDays = (strtotime($yearAgoTo) - strtotime($yearAgoFrom)) / 86400;
    } else {
        $usualFrom = date('Y-m-d', strtotime($last['from'] . ' -90 days'));
        $usualUnits = computeUnitsInWindow($earlier, $usualFrom, $last['from']);
        $usualDays = (strtotime($last['from']) - strtotime(max($usualFrom, $segments[0]['from']))) / 86400;
    }
    if ($usualDays < 7 || $usualUnits <= 0) {
        return null;
    }
    $usual = $usualUnits / $usualDays;
    $ratio = $last['per_day'] / $usual;
    return $ratio >= $threshold
        ? ['from' => $last['from'], 'to' => $last['to'], 'per_day' => round($last['per_day'], 3), 'usual' => round($usual, 3), 'ratio' => round($ratio, 2)]
        : null;
}

/**
 * Typical yearly consumption of a household, in the unit of the meter.
 * Electricity: Stromspiegel values for a household without electric hot water.
 * Water: about 125 litres per person and day. Gas heating: about 140 kWh per m² and year.
 * Heat pump: about 40 kWh per m² and year. Null when the household data is missing.
 */
function householdBenchmark(array $meter, int $persons, float $livingArea): ?float
{
    $type = $meter['meter_type'];
    if ($type === 'electricity' && !empty($meter['is_heating'])) {
        return $livingArea > 0 ? round($livingArea * 40) : null;
    }
    if ($type === 'electricity' && $persons > 0) {
        $table = [1 => 1500, 2 => 2500, 3 => 3200, 4 => 4000, 5 => 4700];
        return (float) ($table[$persons] ?? 4700 + ($persons - 5) * 700);
    }
    if ($type === 'water' && $persons > 0) {
        return round($persons * 45.6, 1); // m³
    }
    if ($type === 'gas' && $livingArea > 0) {
        $kwh = $livingArea * 140;
        return round($kwh / (meterConversion($meter) > 1 ? meterConversion($meter) : 10.5)); // m³
    }
    return null;
}

/** Heating degree days per month (base 15 °C): sum of max(0, 15 - daily mean). */
function heatingDegreeDays(array $dailyMeans, float $base = 15.0): array
{
    $months = [];
    foreach ($dailyMeans as $day => $mean) {
        if ($mean === null) {
            continue;
        }
        $key = substr($day, 0, 7);
        $months[$key] = ($months[$key] ?? 0.0) + max(0.0, $base - (float) $mean);
    }
    ksort($months);
    return array_map(fn($v) => round($v, 1), $months);
}

/**
 * Weather-adjusted consumption per calendar year: units, degree days, units per degree day
 * and the consumption scaled to the average degree days of all complete years shown.
 * Only months that have both consumption and weather data are counted.
 */
function weatherAdjustedYears(array $monthly, array $degreeDays): array
{
    $years = [];
    foreach ($monthly as $month => $units) {
        if (!isset($degreeDays[$month])) {
            continue;
        }
        $y = (int) substr($month, 0, 4);
        $years[$y] = $years[$y] ?? ['units' => 0.0, 'hdd' => 0.0, 'months' => 0];
        $years[$y]['units'] += $units;
        $years[$y]['hdd'] += $degreeDays[$month];
        $years[$y]['months']++;
    }
    ksort($years);
    $complete = array_filter($years, fn($y) => $y['months'] === 12 && $y['hdd'] > 0);
    $avgHdd = $complete ? array_sum(array_column($complete, 'hdd')) / count($complete) : null;
    foreach ($years as &$y) {
        $y['units'] = round($y['units'], 1);
        $y['hdd'] = round($y['hdd'], 1);
        $y['per_hdd'] = $y['hdd'] > 0 ? round($y['units'] / $y['hdd'], 3) : null;
        $y['adjusted'] = ($avgHdd !== null && $y['months'] === 12 && $y['hdd'] > 0) ? round($y['units'] * $avgHdd / $y['hdd'], 1) : null;
    }
    return $years;
}

/**
 * PV figures for a window: generation, feed-in, self-consumption, grid draw,
 * self-consumption rate and autarky. Null when there is no generation in the window.
 */
function pvBalance(float $generation, float $feedIn, float $grid): ?array
{
    if ($generation <= 0) {
        return null;
    }
    $self = max(0.0, $generation - $feedIn);
    $demand = $self + $grid;
    return [
        'generation' => round($generation, 1),
        'feed_in' => round($feedIn, 1),
        'self' => round($self, 1),
        'grid' => round($grid, 1),
        'self_rate' => round($self / $generation * 100, 1),
        'autarky' => $demand > 0 ? round($self / $demand * 100, 1) : null,
    ];
}

/**
 * Payback of a PV system: yearly benefit = self-consumed kWh at the grid price + feed-in pay.
 * @return array{benefit: float, years: float|null, payback_date: string|null, recovered: float, percent: float}|null
 */
function pvAmortization(float $investment, ?string $commissioning, float $selfPerYear, float $gridPrice, float $feedInPerYear, float $feedInPrice, string $today): ?array
{
    if ($investment <= 0) {
        return null;
    }
    $benefit = $selfPerYear * $gridPrice + $feedInPerYear * $feedInPrice;
    $years = $benefit > 0 ? $investment / $benefit : null;
    $elapsed = $commissioning ? max(0, (strtotime($today) - strtotime($commissioning)) / 86400 / 365.25) : 0;
    $recovered = min($investment, $benefit * $elapsed);
    return [
        'benefit' => round($benefit, 2),
        'years' => $years !== null ? round($years, 1) : null,
        'payback_date' => ($years !== null && $commissioning) ? date('Y-m-d', (int) (strtotime($commissioning) + $years * 365.25 * 86400)) : null,
        'recovered' => round($recovered, 2),
        'percent' => round($recovered / $investment * 100, 1),
    ];
}

/**
 * Compares a supplier bill with the own readings and prices of the same period.
 * @return array{units: float|null, cost: float|null, unit_diff: float|null, cost_diff: float|null, status: string}
 */
function checkBill(array $bill, array $segments, array $tariffs, float $conversion, bool $withBaseFee): array
{
    $to = date('Y-m-d', strtotime($bill['period_to'] . ' +1 day')); // bill periods include their last day
    $covered = computeUnitsInWindow($segments, $bill['period_from'], $to);
    $firstReading = $segments[0]['from'] ?? null;
    $lastReading = $segments ? end($segments)['to'] : null;
    if ($firstReading === null || $firstReading > $bill['period_from'] || $lastReading < $bill['period_to']) {
        return ['units' => null, 'cost' => null, 'unit_diff' => null, 'cost_diff' => null, 'status' => 'no_data'];
    }
    $cost = costInWindow($segments, $tariffs, $bill['period_from'], $to, $conversion, $withBaseFee)['total'];
    $unitDiff = $covered > 0 ? ($bill['units'] - $covered) / $covered * 100 : null;
    $costDiff = $cost > 0 ? ($bill['amount'] - $cost) / $cost * 100 : null;
    $worst = max(abs($unitDiff ?? 0), abs($costDiff ?? 0));
    return [
        'units' => round($covered, 1),
        'cost' => round($cost, 2),
        'unit_diff' => $unitDiff !== null ? round($unitDiff, 1) : null,
        'cost_diff' => $costDiff !== null ? round($costDiff, 1) : null,
        'status' => $worst > 10 ? 'deviation' : ($worst > 3 ? 'check' : 'ok'),
    ];
}

/* ---------- database / network helpers ---------- */

function loadConsumptionSettings(SQLite3 $db, int $userId): array
{
    $stmt = $db->prepare('SELECT * FROM consumption_settings WHERE user_id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: [];
    if (empty($row['persons'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM household WHERE user_id = :userId');
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $row['persons_default'] = (int) $stmt->execute()->fetchArray()[0];
    }
    return $row + ['persons' => 0, 'living_area' => 0, 'location_name' => '', 'latitude' => null, 'longitude' => null];
}

function loadMeterRows(SQLite3 $db, string $table, int $meterId, int $userId, string $order): array
{
    $stmt = $db->prepare("SELECT * FROM $table WHERE meter_id = :meterId AND user_id = :userId ORDER BY $order");
    $stmt->bindValue(':meterId', $meterId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Daily mean temperatures for a location from the Open-Meteo archive (no account),
 * cached in weather_daily. Only the coordinates leave the server, rounded to 0.01°.
 * Returns [Y-m-d => °C]; missing days stay missing when the service is unreachable.
 */
function loadDailyTemperatures(SQLite3 $db, float $lat, float $lon, string $from, string $to): array
{
    $location = sprintf('%.2f,%.2f', $lat, $lon);
    $to = min($to, date('Y-m-d', strtotime('-2 days'))); // the archive lags a few days behind
    $stmt = $db->prepare('SELECT day, temp_mean FROM weather_daily WHERE location = :loc AND day BETWEEN :from AND :to');
    $stmt->bindValue(':loc', $location);
    $stmt->bindValue(':from', $from);
    $stmt->bindValue(':to', $to);
    $result = $stmt->execute();
    $days = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $days[$row['day']] = $row['temp_mean'];
    }
    $expected = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($expected <= 0 || count($days) >= $expected) {
        return $days;
    }

    // Fetch only from the first missing day on.
    $fetchFrom = $from;
    while (isset($days[$fetchFrom]) && $fetchFrom < $to) {
        $fetchFrom = date('Y-m-d', strtotime($fetchFrom . ' +1 day'));
    }
    $url = 'https://archive-api.open-meteo.com/v1/archive?' . http_build_query([
        'latitude' => sprintf('%.2f', $lat), 'longitude' => sprintf('%.2f', $lon),
        'start_date' => $fetchFrom, 'end_date' => $to, 'daily' => 'temperature_2m_mean', 'timezone' => 'auto',
    ]);
    $json = consumptionHttpGet($url);
    $data = $json ? json_decode($json, true) : null;
    if (!isset($data['daily']['time'])) {
        return $days;
    }
    $db->exec('BEGIN');
    $insert = $db->prepare('INSERT OR REPLACE INTO weather_daily (location, day, temp_mean) VALUES (:loc, :day, :t)');
    foreach ($data['daily']['time'] as $i => $day) {
        $t = $data['daily']['temperature_2m_mean'][$i] ?? null;
        if ($t === null) {
            continue;
        }
        $insert->bindValue(':loc', $location);
        $insert->bindValue(':day', $day);
        $insert->bindValue(':t', (float) $t, SQLITE3_FLOAT);
        $insert->execute();
        $insert->reset();
        $days[$day] = (float) $t;
    }
    $db->exec('COMMIT');
    ksort($days);
    return $days;
}

/** Small GET with timeout; cURL when available. Returns the body or null. */
function consumptionHttpGet(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Wallos-Extended']);
        $body = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok && is_string($body) ? $body : null;
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: Wallos-Extended\r\n"]]));
    return $body === false ? null : $body;
}
