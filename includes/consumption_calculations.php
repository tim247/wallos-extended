<?php
// Consumption tracker calculations. Readings are absolute meter values
// (Zählerstände); consumption is the difference between consecutive readings.

/**
 * Turns sorted meter readings into consumption segments.
 *
 * @param array $readings Rows with reading_date, reading_value, is_meter_change,
 *                        ordered by reading_date ascending.
 * @return array{segments: array, warnings: array}
 */
function computeConsumptionSegments(array $readings): array
{
    $segments = [];
    $warnings = [];

    for ($i = 1; $i < count($readings); $i++) {
        $prev = $readings[$i - 1];
        $curr = $readings[$i];

        if (!empty($curr['is_meter_change'])) {
            // Meter was replaced: no consumption can be derived across this point.
            continue;
        }

        $delta = (float) $curr['reading_value'] - (float) $prev['reading_value'];
        $days = (int) ((strtotime($curr['reading_date']) - strtotime($prev['reading_date'])) / 86400);

        if ($days <= 0) {
            continue;
        }
        if ($delta < 0) {
            $warnings[] = $curr['reading_date'];
            continue;
        }

        $segments[] = [
            'from' => $prev['reading_date'],
            'to' => $curr['reading_date'],
            'delta' => $delta,
            'days' => $days,
            'per_day' => $delta / $days,
        ];
    }

    return ['segments' => $segments, 'warnings' => $warnings];
}

/**
 * Distributes consumption segments onto calendar months for charting.
 *
 * @return array<string,float> e.g. ['2026-01' => 123.4, ...] sorted by month.
 */
function computeMonthlyBuckets(array $segments): array
{
    $buckets = [];

    foreach ($segments as $segment) {
        $cursor = new DateTime($segment['from']);
        $end = new DateTime($segment['to']);

        while ($cursor < $end) {
            $monthEnd = (clone $cursor)->modify('last day of this month')->modify('+1 day');
            $sliceEnd = min($monthEnd, $end);
            $daysInSlice = max((int) $cursor->diff($sliceEnd)->days, 0);

            $key = $cursor->format('Y-m');
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $segment['per_day'] * $daysInSlice;

            $cursor = $sliceEnd;
        }
    }

    ksort($buckets);
    return array_map(fn($v) => round($v, 2), $buckets);
}

/**
 * Forecasts consumption from the last reading until $targetDate.
 *
 * Uses the weighted average daily consumption of the segments within the
 * lookback window. If data for the same target window one year earlier
 * exists, the seasonal average is used and scaled by the trend between
 * the recent period and the same period last year.
 *
 * @return array{
 *   per_day: float, projected_units: float, from: string|null, to: string,
 *   days: int, method: string
 * }|null Null when there is not enough data (fewer than 1 segment).
 */
function computeForecast(array $segments, string $targetDate, int $lookbackDays = 90, string $mode = 'auto'): ?array
{
    if (count($segments) === 0) {
        return null;
    }

    $lastSegment = end($segments);
    $forecastStart = $lastSegment['to'];
    $daysAhead = (int) ((strtotime($targetDate) - strtotime($forecastStart)) / 86400);
    if ($daysAhead <= 0) {
        $daysAhead = 0;
    }

    $windowFn = function (string $from, string $to) use ($segments): array {
        $units = 0.0;
        $days = 0;
        foreach ($segments as $segment) {
            $overlapStart = max(strtotime($segment['from']), strtotime($from));
            $overlapEnd = min(strtotime($segment['to']), strtotime($to));
            $overlapDays = (int) (($overlapEnd - $overlapStart) / 86400);
            if ($overlapDays > 0) {
                $units += $segment['per_day'] * $overlapDays;
                $days += $overlapDays;
            }
        }
        return [$units, $days];
    };

    // Recent average (lookback window ending at the last reading).
    $recentFrom = date('Y-m-d', strtotime($forecastStart . ' -' . $lookbackDays . ' days'));
    [$recentUnits, $recentDays] = $windowFn($recentFrom, $forecastStart);

    if ($recentDays <= 0) {
        return null;
    }
    $recentPerDay = $recentUnits / $recentDays;

    $perDay = $recentPerDay;
    $method = 'recent_average';

    // Seasonal adjustment: same target window, one year earlier.
    $seasonFrom = date('Y-m-d', strtotime($forecastStart . ' -1 year'));
    $seasonTo = date('Y-m-d', strtotime($targetDate . ' -1 year'));
    [$seasonUnits, $seasonDays] = $windowFn($seasonFrom, $seasonTo);

    $lastYearRecentFrom = date('Y-m-d', strtotime($recentFrom . ' -1 year'));
    $lastYearRecentTo = date('Y-m-d', strtotime($forecastStart . ' -1 year'));
    [$lyRecentUnits, $lyRecentDays] = $windowFn($lastYearRecentFrom, $lastYearRecentTo);

    // $mode 'recent' keeps the plain average; 'auto' and 'previous_year' use last year when there is enough of it.
    if ($mode !== 'recent' && $seasonDays >= max(1, (int) ($daysAhead * 0.5)) && $lyRecentDays > 0 && $lyRecentUnits > 0) {
        $seasonPerDay = $seasonUnits / $seasonDays;
        $trend = $recentPerDay / ($lyRecentUnits / $lyRecentDays);
        // Keep the trend factor within sane bounds.
        $trend = max(0.5, min(2.0, $trend));
        $perDay = $seasonPerDay * $trend;
        $method = 'seasonal';
    }

    return [
        'per_day' => round($perDay, 4),
        'projected_units' => round($perDay * $daysAhead, 2),
        'from' => $forecastStart,
        'to' => $targetDate,
        'days' => $daysAhead,
        'method' => $method,
    ];
}

/**
 * Total consumption units within a calendar window (e.g. year to date).
 */
function computeUnitsInWindow(array $segments, string $from, string $to): float
{
    $units = 0.0;
    foreach ($segments as $segment) {
        $overlapStart = max(strtotime($segment['from']), strtotime($from));
        $overlapEnd = min(strtotime($segment['to']), strtotime($to));
        $overlapDays = (int) (($overlapEnd - $overlapStart) / 86400);
        if ($overlapDays > 0) {
            $units += $segment['per_day'] * $overlapDays;
        }
    }
    return round($units, 2);
}

/**
 * Forecast for gas / heating from heating degree days instead of a plain average.
 *
 * Fits units = base_per_day * days + per_hdd * HDD over the reading segments of the last two years
 * (HDD = sum of max(0, 15 °C - daily mean)), then projects from the last reading to $targetDate:
 * days with a measured temperature use it, later days the mean HDD of the same calendar day in the
 * years on record. Null when the temperatures do not cover enough segments or the fit makes no sense
 * - the caller then falls back to computeForecast().
 *
 * @param array $temps [Y-m-d => daily mean °C]
 */
function computeDegreeDayForecast(array $segments, string $targetDate, array $temps, float $baseTemp = 15.0): ?array
{
    if (count($segments) === 0 || count($temps) < 365) {
        return null;
    }
    $hdd = fn(float $t) => max(0.0, $baseTemp - $t);
    $last = end($segments)['to'];
    $fitFrom = date('Y-m-d', strtotime($last . ' -730 days'));

    // Least squares without intercept: units = a * days + b * hdd (2x2 normal equations).
    $sdd = $sdh = $shh = $sud = $suh = 0.0;
    $used = 0;
    foreach ($segments as $segment) {
        if ($segment['to'] <= $fitFrom || $segment['days'] <= 0) {
            continue;
        }
        $known = 0;
        $segmentHdd = 0.0;
        for ($d = $segment['from']; $d < $segment['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (isset($temps[$d])) {
                $known++;
                $segmentHdd += $hdd((float) $temps[$d]);
            }
        }
        if ($known < $segment['days'] * 0.9) {
            continue; // weather missing for this stretch
        }
        $segmentHdd *= $segment['days'] / $known;
        $days = (float) $segment['days'];
        $units = (float) $segment['delta'];
        $sdd += $days * $days;
        $sdh += $days * $segmentHdd;
        $shh += $segmentHdd * $segmentHdd;
        $sud += $units * $days;
        $suh += $units * $segmentHdd;
        $used++;
    }
    $det = $sdd * $shh - $sdh * $sdh;
    if ($used < 4 || $shh <= 0 || abs($det) < 1e-9) {
        return null;
    }
    $perDay = ($sud * $shh - $suh * $sdh) / $det;
    $perHdd = ($suh * $sdd - $sud * $sdh) / $det;
    if ($perDay < 0) { // no negative base load: all of it is heating
        $perDay = 0.0;
        $perHdd = $suh / $shh;
    }
    if ($perHdd <= 0) {
        return null; // consumption does not follow the temperature (e.g. no heating on this meter)
    }

    // Typical HDD per calendar day from the years on record.
    $climate = [];
    foreach ($temps as $day => $t) {
        $md = substr($day, 5) === '02-29' ? '02-28' : substr($day, 5);
        $climate[$md][] = $hdd((float) $t);
    }
    $daysAhead = 0;
    $projected = 0.0;
    for ($d = $last; $d < $targetDate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $md = substr($d, 5) === '02-29' ? '02-28' : substr($d, 5);
        if (isset($temps[$d])) {
            $dayHdd = $hdd((float) $temps[$d]);
        } elseif (!empty($climate[$md])) {
            $dayHdd = array_sum($climate[$md]) / count($climate[$md]);
        } else {
            return null;
        }
        $projected += $perDay + $perHdd * $dayHdd;
        $daysAhead++;
    }
    return [
        'per_day' => $daysAhead > 0 ? round($projected / $daysAhead, 4) : round($perDay, 4),
        'projected_units' => round($projected, 2),
        'from' => $last,
        'to' => $targetDate,
        'days' => $daysAhead,
        'method' => 'degree_days',
        'base_per_day' => round($perDay, 3),
        'per_hdd' => round($perHdd, 4),
    ];
}
