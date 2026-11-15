<?php
// Deadlines & maintenance and the household inventory (reminders.php, inventory.php).

/** Category => [kind, Font Awesome icon, default interval in months]. */
const REMINDER_CATEGORIES = [
    'id_card' => ['document', 'fa-id-card', 120],
    'passport' => ['document', 'fa-passport', 120],
    'driving_licence' => ['document', 'fa-id-badge', 180],
    'vaccination' => ['document', 'fa-syringe', 120],
    'health_card' => ['document', 'fa-notes-medical', 60],
    'tuev' => ['maintenance', 'fa-car', 24],
    'car_service' => ['maintenance', 'fa-screwdriver-wrench', 12],
    'oil_change' => ['maintenance', 'fa-oil-can', 12],
    'gas_check' => ['maintenance', 'fa-fire-flame-simple', 24],
    'heating' => ['maintenance', 'fa-temperature-arrow-up', 12],
    'filter' => ['maintenance', 'fa-filter', 6],
    'smoke_detector' => ['maintenance', 'fa-bell', 12],
    'chimney' => ['maintenance', 'fa-house-chimney', 12],
    'other' => ['maintenance', 'fa-calendar-check', 12],
];

/** Next due date after "done today": the interval counts from today (service was done now). */
function reminderNextDue(string $today, int $intervalMonths): ?string
{
    return $intervalMonths > 0 ? date('Y-m-d', strtotime("$today +$intervalMonths months")) : null;
}

/**
 * Current value with straight-line depreciation over the lifetime (never below 0).
 * Without a lifetime or date the purchase price counts.
 */
function inventoryCurrentValue(float $price, ?string $purchaseDate, int $lifetimeYears, ?string $today = null): float
{
    if ($lifetimeYears <= 0 || empty($purchaseDate)) {
        return round($price, 2);
    }
    $years = (strtotime($today ?? date('Y-m-d')) - strtotime($purchaseDate)) / (365.25 * 86400);
    return round(max(0.0, $price * (1 - max(0, $years) / $lifetimeYears)), 2);
}
