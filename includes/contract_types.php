<?php
// Contract types with their own fields (stored as JSON in contracts.details), the statutory
// notice period for consumer contracts, leasing mileage and the switch calculator.

/** type => [icon, consumer contract (Faire-Verbraucherverträge-Gesetz), fields: key => input type] */
const CONTRACT_TYPES = [
    'electricity' => ['fa-bolt', true, []],
    'gas' => ['fa-fire', true, []],
    'internet' => ['fa-wifi', true, ['bandwidth' => 'number']],
    'mobile' => ['fa-mobile-screen', true, ['data_volume' => 'number', 'phone_number' => 'text']],
    'fitness' => ['fa-dumbbell', true, []],
    'streaming' => ['fa-tv', true, []],
    'rent' => ['fa-house', false, ['cold_rent' => 'money', 'utilities' => 'money', 'deposit' => 'money', 'deposit_where' => 'text', 'utilities_result' => 'money']],
    'leasing' => ['fa-car-side', false, ['residual_value' => 'money', 'km_per_year' => 'number', 'km_current' => 'number', 'km_date' => 'date', 'extra_km_cost' => 'money', 'return_date' => 'date']],
    'service' => ['fa-screwdriver-wrench', false, ['next_service' => 'date', 'service_interval' => 'number']],
    'other' => ['fa-file-signature', false, []],
];

/** Unit shown inside a detail field and after its value; money fields show the currency. */
const CONTRACT_DETAIL_UNITS = ['bandwidth' => 'Mbit/s', 'data_volume' => 'GB', 'km_per_year' => 'km', 'km_current' => 'km'];

/** Details of a contract row, only the fields of its type. */
function contractDetails(array $contract): array
{
    $type = CONTRACT_TYPES[$contract['contract_type'] ?? 'other'] ?? CONTRACT_TYPES['other'];
    $stored = json_decode((string) ($contract['details'] ?? ''), true) ?: [];
    return array_intersect_key($stored, $type[2]);
}

/** Cleans posted detail fields for a type: numbers as numbers, dates as Y-m-d, text cut short. */
function contractDetailsFromRequest(string $type, array $post): array
{
    $details = [];
    foreach ((CONTRACT_TYPES[$type] ?? CONTRACT_TYPES['other'])[2] as $key => $kind) {
        $value = trim((string) ($post['detail_' . $key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($kind === 'money' || $kind === 'number') {
            $details[$key] = round((float) str_replace(',', '.', $value), 2);
        } elseif ($kind === 'date') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $details[$key] = $value;
            }
        } else {
            $details[$key] = htmlspecialchars(mb_substr(strip_tags($value), 0, 100));
        }
    }
    return $details;
}

/**
 * Statutory terms for consumer contracts concluded from 1 March 2022 (Faire-Verbraucherverträge-Gesetz):
 * after the minimum term they renew monthly and can be cancelled with one month's notice.
 * @return array{renewal: int, notice: int}|null Null when the rule does not apply.
 */
function statutoryContractTerms(string $type, ?string $startDate): ?array
{
    if (!(CONTRACT_TYPES[$type][1] ?? false) || !$startDate || $startDate < '2022-03-01') {
        return null;
    }
    return ['renewal' => 1, 'notice' => 1];
}

/**
 * Leasing: mileage expected at the return date and what the extra kilometres cost.
 * @return array{expected: int, allowed: int, extra_km: int, extra_cost: float}|null
 */
function leasingMileage(array $details, ?string $startDate, string $today): ?array
{
    $perYear = (float) ($details['km_per_year'] ?? 0);
    $current = (float) ($details['km_current'] ?? 0);
    $return = $details['return_date'] ?? null;
    $measured = $details['km_date'] ?? $today;
    if ($perYear <= 0 || !$return || !$startDate || $measured <= $startDate) {
        return null;
    }
    $daysDriven = (strtotime($measured) - strtotime($startDate)) / 86400;
    $daysTotal = (strtotime($return) - strtotime($startDate)) / 86400;
    $expected = (int) round($current / $daysDriven * $daysTotal);
    $allowed = (int) round($perYear * $daysTotal / 365.25);
    $extra = max(0, $expected - $allowed);
    return ['expected' => $expected, 'allowed' => $allowed, 'extra_km' => $extra, 'extra_cost' => round($extra * (float) ($details['extra_km_cost'] ?? 0), 2)];
}

/**
 * Switch calculator: current contract against an offer over the same number of months.
 * @return array{current: float, offer: float, saving: float}
 */
function contractSwitchComparison(float $currentMonthly, float $offerMonthly, float $bonus, float $setupFee, int $months): array
{
    $current = round($currentMonthly * $months, 2);
    $offer = round($offerMonthly * $months - $bonus + $setupFee, 2);
    return ['current' => $current, 'offer' => $offer, 'saving' => round($current - $offer, 2)];
}
