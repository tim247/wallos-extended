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
function computeForecast(array $segments, string $targetDate, int $lookbackDays = 90): ?array
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

    if ($seasonDays >= max(1, (int) ($daysAhead * 0.5)) && $lyRecentDays > 0 && $lyRecentUnits > 0) {
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
